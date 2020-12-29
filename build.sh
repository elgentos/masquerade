mkdir -p dist
if [ -f ../box/box.phar ]; then
    php -d phar.readonly=off ../box/box.phar build -v
elif [ -f ./box.phar ]; then
    php -d phar.readonly=off ./box.phar build -v
else
    php -d phar.readonly=off box build -v
fi
