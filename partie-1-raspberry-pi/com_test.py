from client import MK3Client
import time

client = MK3Client("/dev/ttyUSB0")
client.connect()

while True:
    client.request_data()
    time.sleep(0.2)

    raw = client.dev.read(64)
    if raw:
        print("RAW:", raw.hex())
