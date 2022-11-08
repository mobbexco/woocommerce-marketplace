#!/bin/sh

VER="1.5.0"

# Copy files to temp dir
if robocopy > /dev/null; then
    robocopy . mobbex-marketplace /MIR /XD .git .vscode mobbex-marketplace /XF .gitignore build.sh readme.md *.zip
elif type rsync > /dev/null; then
    rsync -r --exclude={'.git','.vscode','mobbex-marketplace','.gitignore','build.sh','readme.md','*.zip'} . ./mobbex-marketplace
fi

# Compress
if type 7z > /dev/null; then
    7z a -tzip "wc-mobbex-marketplace.$VER.zip" mobbex-marketplace
elif type zip > /dev/null; then
    zip wc-mobbex-marketplace.$VER.zip -r mobbex-marketplace
fi

# Remove temp dir
rm -r ./mobbex-marketplace