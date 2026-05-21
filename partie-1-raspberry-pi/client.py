import time
from mk3 import MK3Device
from protocol import extract_frames, parse_frame, parse_multi_frame
from decoder import decode


class MK3Client:
    def __init__(self, port="/dev/ttyUSB0"):
        self.dev = MK3Device(port=port)
        self.buffer = b""

    def connect(self):
        self.dev.open()
        self._init_session()

    def _init_session(self):
    # reset
        self.dev.write(bytes.fromhex("555555555502ff4cb3"))
        time.sleep(0.5)

    # sync
        self.dev.write(bytes.fromhex("061e00000000e1"))
        time.sleep(0.5)

    #  ouverture VE.Bus (cle)
        self.dev.write(bytes.fromhex("061e00000000e1"))
        time.sleep(0.5)

        self.dev.write(bytes.fromhex("061e00000000e1"))
        time.sleep(0.5)  


    def request_data(self):
        self.dev.write(bytes.fromhex("04ff4101ffbc"))



    def read(self):
        data = self.dev.read(64)

        if not data:
            return []

        self.buffer += data

        frames, self.buffer = extract_frames(self.buffer)

        decoded = []

        for frame in frames:
            print("FRAME:", frame.hex())

            #
            if frame[0] == 0x20:
                multi = parse_multi_frame(frame)

                for item in multi:
                    result = decode(item["cmd"], item["id"], item["value"])
                    if result:
                        decoded.append(result)

                continue

            parsed = parse_frame(frame)
            if not parsed:
                continue

            # ignorer heartbeat
            if parsed["cmd"] in (0x3C, 0x3D):
                continue

            result = decode(parsed["cmd"], parsed["id"], parsed["value"])
            if result:
                decoded.append(result)

        return decoded

    def close(self):
        self.dev.close()
