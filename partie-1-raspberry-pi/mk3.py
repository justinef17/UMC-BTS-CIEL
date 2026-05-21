import serial
import time

class MK3Device:
    def __init__(self, port="/dev/ttyUSB0", baudrate=2400):
        self.port = port
        self.baudrate = baudrate
        self.ser = None

    def open(self):
        self.ser = serial.Serial(
            port=self.port,
            baudrate=self.baudrate,
            bytesize=serial.EIGHTBITS,
            parity=serial.PARITY_NONE,
            stopbits=serial.STOPBITS_ONE,
            timeout=0.2
        )
        time.sleep(0.1)

    def write(self, data: bytes):
        self.ser.write(data)

    def read(self, size=64):
        return self.ser.read(size)

    def close(self):
        if self.ser and self.ser.is_open:
            self.ser.close()
