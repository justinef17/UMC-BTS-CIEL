def checksum(data: bytes) -> int:
    s = sum(data) & 0xFF
    return (-s) & 0xFF


def extract_frames(buffer: bytes):
    frames = []
    i = 0

    while i < len(buffer):
        length = buffer[i]

        if length < 5 or length > 64:
            i += 1
            continue

        if i + length > len(buffer):
            break

        frame = buffer[i:i+length]

        if checksum(frame[:-1]) == frame[-1]:
            frames.append(frame)
            i += length
        else:
            i += 1

    return frames, buffer[i:]


def parse_frame(frame: bytes):
    length = frame[0]
    flags = frame[1]
    cmd = frame[2]
    var_id = frame[3]

    payload = frame[4:-1]

    if len(payload) == 2:
        value = int.from_bytes(payload, 'little', signed=True)
    elif len(payload) >= 4:
        value = int.from_bytes(payload[:4], 'little', signed=True)
    else:
        value = None

    return {
        "cmd": cmd,
        "id": var_id,
        "value": value,
        "raw": frame
    }


def parse_multi_frame(frame: bytes):
    results = []
    i = 1  # skip length

    while i < len(frame) - 1:
        try:
            cmd = frame[i]
            var_id = frame[i+1]

            value = int.from_bytes(frame[i+2:i+6], 'little', signed=True)

            results.append({
                "cmd": cmd,
                "id": var_id,
                "value": value
            })

            i += 6
        except:
            break

    return results
