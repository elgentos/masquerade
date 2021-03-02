mkdir -p dist
if [ -f ../box/box.phar ]; then
    php -d phar.readonly=off ../box/box.phar compile -v
elif [ -f ./box.phar ]; then
    php -d phar.readonly=off ./box.phar compile -v
else
    php -d phar.readonly=off box compile -v
fi
