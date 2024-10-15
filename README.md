# securitycam-object-detection

I have a HikVision camera. The camera is set up so it calls a url whenever motion is detected.  
On this url I send an image from the camera to Google Cloud Vision API to detect the objects.  
If there are any objects detected, I send a notification to my phone using PushOver.