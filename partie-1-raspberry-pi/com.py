from client import MK3Client
import time

client = MK3Client()
client.connect()

while True:
    client.request_data()

    time.sleep(0.3)  

#    for _ in range(10):
    data = client.read()
    if data:
        print(data)

    time.sleep(0.05)  
