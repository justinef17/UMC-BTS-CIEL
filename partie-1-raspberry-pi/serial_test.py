import serial
ser = serial.Serial('/dev/ttyUSB0', 2400, timeout=1)
print("OK connecte")

