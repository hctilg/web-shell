# Web-Shell

This is a **web-shell** with php

You can run system commands and upload files to the server.

## How to test it?

Go to the `web-shell` folder and run php localhost:

<!--
```bash
php -S $(ifconfig | grep "inet " | grep -v 127.0.0.1 | cut -d ' ' -f10):6060
```
-->

```bash
php -S localhost:6060
```

then open the following url
```
http://localhost:6060/shell.php
```

## Preview

<img src="preview/terminal.png" width="800px" alt="preview-terminal"/>

<table>
  <tr>
    <td>
      <img src="preview/main.png" width="340px" alt="preview-main"/>
    </td>
    <td>
      <img src="preview/info.png" width="340px" alt="preview-info"/>
    </td>
  </tr>
  <tr>
    <td>
      <img src="preview/upload.png" width="340px" alt="preview-upload"/>
    </td>
    <td>
      <img src="preview/uploaded.png" width="340px" alt="preview-uploaded"/>
    </td>
  </tr>
</table>