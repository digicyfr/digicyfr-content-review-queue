#!/usr/bin/env python3
"""Compile this plugin's simple singular PO catalog to a GNU MO file."""

from __future__ import annotations

import ast
from pathlib import Path
import struct


directory = Path(__file__).resolve().parent
source = directory / "digicyfr-content-review-queue-pl_PL.po"
target = directory / "digicyfr-content-review-queue-pl_PL.mo"


def messages_from_po(text: str) -> dict[str, str]:
    messages: dict[str, str] = {}
    msgid: str | None = None
    msgstr: str | None = None
    active: str | None = None

    def flush() -> None:
        if msgid is not None and msgstr is not None:
            if msgid in messages:
                raise ValueError(f"Duplicate msgid: {msgid}")
            messages[msgid] = msgstr

    for line in list(text.splitlines()) + ["msgid \"__END__\""]:
        if line.startswith("msgid "):
            flush()
            msgid = ast.literal_eval(line[6:])
            msgstr = None
            active = "id"
        elif line.startswith("msgstr "):
            msgstr = ast.literal_eval(line[7:])
            active = "str"
        elif line.startswith('"'):
            fragment = ast.literal_eval(line)
            if active == "id" and msgid is not None:
                msgid += fragment
            elif active == "str" and msgstr is not None:
                msgstr += fragment
        elif line and not line.startswith("#"):
            raise ValueError(f"Unsupported PO line: {line}")
    return messages


def make_mo(messages: dict[str, str]) -> bytes:
    keys = sorted(messages, key=lambda value: value.encode("utf-8"))
    ids = [key.encode("utf-8") for key in keys]
    translations = [messages[key].encode("utf-8") for key in keys]
    count = len(keys)
    ids_offset = 28 + count * 16
    strings_offset = ids_offset + sum(len(value) + 1 for value in ids)
    id_table = []
    str_table = []
    id_data = b""
    str_data = b""
    for value in ids:
        id_table.append((len(value), ids_offset + len(id_data)))
        id_data += value + b"\0"
    for value in translations:
        str_table.append((len(value), strings_offset + len(str_data)))
        str_data += value + b"\0"
    header = struct.pack("<7I", 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
    tables = b"".join(struct.pack("<2I", *item) for item in id_table + str_table)
    return header + tables + id_data + str_data


if __name__ == "__main__":
    target.write_bytes(make_mo(messages_from_po(source.read_text(encoding="utf-8"))))
    print(target)
