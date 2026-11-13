#!/bin/bash
cd /home/chairboy/fccspacewatch
/usr/local/bin/wkhtmltopdf --margin-top 0 --margin-bottom 0 "$1" /home/chairboy/fccspacewatch/license_renders/$2.pdf
pdftoppm /home/chairboy/fccspacewatch/license_renders/$2.pdf /home/chairboy/fccspacewatch/license_renders/$2.png -png 
convert -append /home/chairboy/fccspacewatch/license_renders/$2.png-1.png /home/chairboy/fccspacewatch/license_renders/$2.png-2.png  /home/chairboy/fccspacewatch/license_renders/$2.png
rm /home/chairboy/fccspacewatch/license_renders/$2.png-*
rm /home/chairboy/fccspacewatch/license_renders/$2.pdf