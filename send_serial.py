# coding: utf-8

import serial
import time
import sys

def main():
    try:
        args = sys.argv
        ser = serial.Serial(port='COMX or /dev/tty.usbmodemxxxxx',baudrate=9600,timeout=None)
        while True:
            if ser.out_waiting == 0:
                break

        time.sleep(2)

        ser.write((str(args[1]) + '\n\r').encode())
        ser.flush()
        time.sleep(0.1)

        recv_data_string = ''
        while True:
            if ser.in_waiting > 0:
                recv_data_string += ser.read(1).decode()
            else:
                print(recv_data_string)
                break

        ser.close()
    
    except Exception as e:
        print(e)
        ser.close()
    
if __name__ == '__main__':
    main()