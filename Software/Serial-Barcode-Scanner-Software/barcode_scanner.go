// barcode_scanner is designed to work with a barcode scanner configured
// as a serial device.  It assumes it will receive a URL link and it will
// do an http get to the link.
package main

import (
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"

	"go.bug.st/serial"
	"go.bug.st/serial/enumerator"
)

var port serial.Port
var buff []byte

/*-----------------------------------------------------------------------
 *  Wait till a USB Serial Scanner appears
 *---------------------------------------------------------------------*/
func waitForScanner() string {
	for {
		port, err := findScanner()
		if err == nil && port != "" {
			return port
		}
		time.Sleep(time.Second * 5)
	}
}

/*-----------------------------------------------------------------------
 *  Get list of devices and look for a com port
 *---------------------------------------------------------------------*/
func findScanner() (string, error) {
	ports, err := enumerator.GetDetailedPortsList()
	if err != nil {
		return "", err
	}
	if len(ports) == 0 {
		return "", fmt.Errorf("no serial ports found")
	}
	for _, port := range ports {
		// On Linux the serial library never populates Product (see usb_linux.go),
		// so only require the Windows-style match when Product is actually set;
		// otherwise fall back to IsUSB alone.
		if port.IsUSB && (port.Product == "" || strings.Contains(port.Product, "USB Serial Device")) {
			fmt.Printf("Found port: %s\n", port.Name)
			fmt.Printf("   Product:%v\n", port.Product)
			fmt.Printf("   USB ID     %s:%s\n", port.VID, port.PID)
			fmt.Printf("   USB serial %s\n", port.SerialNumber)
			return port.Name, nil
		}
	}
	return "", fmt.Errorf("USB serial port not found")
}

/*--------------------------------------------------------------
 *  Read from the comm port until a carriage return
 *-------------------------------------------------------------*/
func read() (string, error) {
	// Read and print the response
	var qrCode string
	var err error
	var n int
	for {
		// Reads up to 100 bytes
		n, err = port.Read(buff)
		if err != nil {
			return "", err
		}
		if n == 0 {
			fmt.Println("\nEOF")
			break
		}
		qrCode = qrCode + string(buff[:n])
		// If we receive a carriage return, stop reading and strip it
		if buff[n-1] == 13 {
			qrCode = qrCode[:len(qrCode)-1]
			break
		}
	}
	return qrCode, nil

}

/*------------------------------------------------------------------------
 * Find the com port used by the scanner, then go into an infinte loop
 * reading the scanner.  This program is designed to run forever.  The
 * only way to stop it is to abort the program or reboot the computer.
 *-----------------------------------------------------------------------*/
func main() {
	mode := &serial.Mode{
		BaudRate: 9600,
		Parity:   serial.NoParity,
		DataBits: 8,
		StopBits: serial.OneStopBit,
	}
	var err error
	var scanCount int
	for {
		/*-------------------------------------------------------------
		 *  Wait for a scanner to be plugged in.  Wait forever.
		 *------------------------------------------------------------*/
		comPort := waitForScanner()
		/*--------------------------------------------------------------
		 * Open the scanner
		 *-------------------------------------------------------------*/
		port, err = serial.Open(comPort, mode)
		if err != nil {
			fmt.Printf("Error opening port %s: %v\n", comPort, err)
			time.Sleep(time.Second * 5)
			continue
		}
		/*--------------------------------------------------------------
		 * Main scanner read loop
		 *-------------------------------------------------------------*/
		buff = make([]byte, 100)
		var resp *http.Response
		for {
			qrCode, err := read()
			if err != nil {
				fmt.Printf("%v\nWaiting for scanner to be available\n", err)
				break
			}
			now := time.Now()
			// check if it is a valid http request
			_, err = url.ParseRequestURI(qrCode)
			switch {
			case err != nil,
				!(strings.Contains(qrCode, "makernexuswiki") && strings.Contains(qrCode, "OVLcheckinout")):
				fmt.Printf("%5v %v INVALID QRCODE: %v\n", "", now.Format("2006-01-02 15:04:05"), qrCode)
				continue
			}
			if resp, err = http.Get(qrCode); err != nil {
				fmt.Printf("http error:%v\n", err)
				continue
			}
			scanCount++

			fmt.Printf("%5v %v %v status: %d\n", scanCount, now.Format("2006-01-02 15:04:05"), qrCode, resp.StatusCode)

		} // for forever
	} // for forever
}
