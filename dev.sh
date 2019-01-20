#!/usr/local/bin/bash
if ! docker images | grep -q "scrapero-php"
then
    docker build -t scrapero-php .
    echo ""
fi

if [[ $# -gt 0 ]]
then
    docker run -it -v $(pwd):/app -v /tmp:/tmp -w /app scrapero-php $@
else 
    echo "Missing arguments."
    echo 'ex. ./dev.sh echo "Hello world!"'
fi
