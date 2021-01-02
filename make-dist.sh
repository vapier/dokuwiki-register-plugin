#!/bin/bash
ver=$(git rev-parse HEAD | cut -c1-8)
mkdir -p dist/register
cp -a register.php{,s} syntax.php{,s} dist/register/
cd dist
tar zcf register.tar.gz register
mv register.tar.gz ..
cd ..
rm -r dist
du -h register.tar.gz
