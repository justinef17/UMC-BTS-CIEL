import serial
import time

ser = serial.Serial('/dev/ttyUSB0', 2400, timeout=1)
ser.write(bytes.fromhex("555555555502ff4cb3"))
time.sleep(0.1)
ser.write(bytes.fromhex("04ff4101ffbc"))

while True:
    data = ser.read(64)
    if data:
        print(data.hex())

