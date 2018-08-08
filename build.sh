if [ -f ../box/box.phar ]; then
    php -d phar.readonly=off ../box/box.phar build -v
else
    php -d phar.readyonly=off box build -v
fi
