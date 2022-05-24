set ver="1.5.0"

:: Create directory with plugin files
robocopy . mobbex-marketplace /MIR /XD .git .vscode mobbex-marketplace /XF .gitignore build.bat readme.md *.zip

:: Compress archive
7z a -tzip wc-mobbex-marketplace.%ver%.zip mobbex-marketplace

:: Delete directory
rd /s /q mobbex-marketplace