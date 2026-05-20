import hid

for d in hid.enumerate():
    print("HID")
    print(d)
    print(hex(d['vendor_id']), hex(d['product_id']), d['product_string'])

