<?php

function getUrl()
{
    // default url
    $url = 'http://blog.stapps.io/';
    // Posted valid URL?
    if (isset($_GET['url'])
        && is_string($_GET['url'])
        && filter_var(trim($_GET['url']), FILTER_VALIDATE_URL)) {
        $url = trim($_GET['url']);
    }
    return $url;
}
function tmpCookieFile($remove=false)
{
    static $ckfile;
    if ( ! $ckfile) {
        $ckfile = tempnam("/tmp", "CURLCOOKIE");
    }
    if ( ! $remove) {
        return $ckfile;
    } else {
        unlink($ckfile); // remove tmp file
    }
}
function request($url)
{
    $ckfile = tmpCookieFile();
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ResponsiveTestingTool/1.0; +http://responsive.stapps.io/)');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $ckfile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $ckfile);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}
function htmlToDomDoc($html)
{
    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    return $doc;
}
function getBaseUrls($url, $html)
{
    $parsedUrl = parse_url($url);
    $filename = basename($parsedUrl['path']);

    // return list of urls needed
    $urls = array(
        'root' => $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/',
        'dir' => preg_replace('/'.preg_quote($filename).'$/', '', $url),
    );

    // look inside html for <base href="..."> tag
    $doc = htmlToDomDoc($html);
    $baseHref = $doc->getElementsByTagName('base');
    if ($baseHref && $baseHref[0]) {
        $baseHrefUrl = trim($baseHref[0]->getAttribute('href'));
        if ($baseHrefUrl != '') {
            $urls['dir'] = getBaseUrls($baseHrefUrl)['dir'];
        }
    }

    return $urls;
}
function fixBaseOfUrl($url, $baseUrls)
{
    $parsedUrl = parse_url($url);
    // does it already have https?:// etc. if so, return now
    if (isset($parsedUrl['scheme'])) {
        if (in_array($parsedUrl['scheme'], ['http', 'https'])) {
            return $url;
        } else {
            // invalid protocol given, e.g. file:// or ftp:// which we wont use
            return null;
        }
    }
    // does it start with "//"
    if (substr($url, 0, 2) == '//') {
        // fix to http:// and return
        return 'http:' . $url;
    }

    // does it start with "/" (absolute path)
    if (substr($url, 0, 1) == '/') {
        // prefix with base root url and return
        return $baseUrls['root'] . $url;
    }

    // else looks like a relative path
    // prefix with base directory url and return
    return $baseUrls['dir'] . $url;
}
function parseHtmlForCss($html, $baseUrls)
{
    // build up a string of all the css and css files content
    $css = '';

    // parse html
    $doc = htmlToDomDoc($html);

    // grab <style> tags
    $styles = $doc->getElementsByTagName('style');
    foreach ($styles as $style) {
        $css = $doc->saveHTML($style);
    }

    // grab <link> css tags
    $links = $doc->getElementsByTagName('link');
    foreach ($links as $link) {
        if (strtolower($link->getAttribute('rel')) == 'stylesheet') {
            // request css content for each css file found
            $linkUrl = fixBaseOfUrl($link->getAttribute('href'), $baseUrls);
            if ($linkUrl) {
                $css .= request($linkUrl);
            }
        }
    }

    // TODO now run over the css and look for @imports

    return $css;
}
function uniqueMultiArray($input)
{
    // @see http://stackoverflow.com/questions/307674/how-to-remove-duplicate-values-from-a-multi-dimensional-array-in-php#946300
    return array_map("unserialize", array_unique(array_map("serialize", $input)));
}
function findCssMediaQueryBreakpoints($css)
{
    $mqs = [];
    if (preg_match_all('/@media.*?(\(.*?\))\s*{/i', $css, $matches)) {
        foreach ($matches[0] as $mq) {
            // i'm currently only caring about width queries
            if (preg_match_all('/((min|max)(-device)?-width)\s*:\s*(([\d\.]+)(px|em))/', $mq, $match)) {
                foreach ($match[1] as $i => $key) {
                    $mqs[] = [$key, $match[4][$i]];
                }
            }
        }
    }
    // make sure we just have unique breakpoints
    $mqs = uniqueMultiArray($mqs);
    // and sort them by smallest first
    usort($mqs, function($a, $b) {
        $aSize = toPx($a[1]);
        $bSize = toPx($b[1]);
        if ($aSize == $bSize) return 0;
        return ($aSize < $bSize) ? -1 : 1;
    });
    return $mqs;
}
function toPx($size)
{
    if (substr($size, -2) == 'px') {
        return substr($size, 0, -2);
    }
    if (substr($size, -2) == 'em') {
        return substr($size, 0, -2) * 13; // 13 -> base px size
    }
    throw new Exception("Size not specified");
}
function variBreakpoint($breakpoint, $variAmount)
{
    $size = toPx($breakpoint[1]);
    if (substr($breakpoint[0], 0, 3) == 'min') { // if min width
        return $size + $variAmount;
    } else { // else max width
        return $size - $variAmount;
    }
}
function mapBreakpointToDevices($breakpoint)
{
    $size = toPx($breakpoint[1]);
    $info = '';
    switch (true) {
        case ($size < 260):
            $info = 'very small screen';
            break;
        case ($size < 480):
            $info = 'small/phone screen';
            break;
        case ($size < 760):
            $info = 'tablet/phablet screen';
            break;
        case ($size < 960):
            $info = 'medium/tablet screen';
            break;
        case ($size < 1200):
            $info = 'large/desktop screen';
            break;
        case ($size < 2400):
            $info = 'extra large screen';
            break;
    }
    return ' (' . $info . ')';
}

// base px font-size
$basePxFontSize = '13';

// vari amount in px, e.g. to vari before or after the min/max widths for display
$variAmount = '10';

// get requested url and return it's html
$url = getUrl();
$html = request($url);
$breakpoints = [];
if ($html != '') {
    // parse html for css
    $css = parseHtmlForCss($html, getBaseUrls($url, $html));
    $breakpoints = findCssMediaQueryBreakpoints($css);
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Responsive CSS Breakpoint Testing</title>
  <meta name="description" content="Test your site at different responsive css breakpoints / media queries">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.css">

  <style>
    body {
        font: <?php echo $basePxFontSize; ?>px/1.36 sans-serif;
    }
    html, body { height: 100%; margin: 0; padding: 0; }
    .iframe-blocks-container {
        width: 100%;
        overflow: auto;
    }
    .iframe-blocks-container-inner {
        width: 10000px;
    }
    .iframe-blocks-container {
        height: calc(100% - 60px);
    }
    .iframe-blocks-container-inner,
    .iframe-block {
        height: calc(100% - 10px);
        margin: 0;
        padding: 0;
    }
    .iframe-block {
        margin-right: 1em;
        float: left;
    }
    .iframe-block.slider {
        margin: auto;
        float: none;
    }
    .iframe-block-title {
        padding: 0.5em;
        background-color: #ECEFF1;
        text-align: center;
    }
    .iframe-block iframe {
        width: 100%;
        height: calc(100% - 50px);
    }
    .iframe-block.slider iframe {
        width: 100%;
        height: 100%;
    }

    .url-form {
        background: #607D8B;
        padding: 1em;
        font-weight: bold;
        color: #FFF;
    }
    .url-form label,
    .url-form input,
    .url-form button {
        display: block;
        float: left;
    }
    .url-form label {
        width: 10%;
    }
    .url-form #url {
        width: 50%;
    }
    .url-form button {
        width: 5%;
        margin-left: 2%;
        cursor: pointer;
    }
    .url-form input,
    .url-form button {
        background-color: #FFF;
        border: 1px solid #455A64;
        padding: 0.5em;
    }
    .url-form button:active {
        background-color: #EEE;
    }

    /* jquery ui resizeable handle, give it a background so it's more obvious here */
    .ui-icon-gripsmall-diagonal-se {
        background-color: #ECEFF1;
    }

    /*
    Better box sizing by default on all elements
    http://css-tricks.com/international-box-sizing-awareness-day/
    */
    *, *:before, *:after {
        /* Chrome 9-, Safari 5-, iOS 4.2-, Android 3-, Blackberry 7- */
        -webkit-box-sizing: border-box;

        /* Firefox (desktop or Android) 28- */
        -moz-box-sizing: border-box;

        /* Firefox 29+, IE 8+, Chrome 10+, Safari 5.1+, Opera 9.5+, iOS 5+, Opera Mini Anything, Blackberry 10+, Android 4+ */
        box-sizing: border-box;
    }


    /* clearfix */
    .clearfix:before,
    .clearfix:after {
    content: " ";
    display: table;
    }
    .clearfix:after {
    clear: both;
    }
    .clearfix {
    *zoom: 1; /* For IE 6/7 (trigger hasLayout) */
    }

    .bx-viewport, .bx-wrapper{
        height:100% !important;
    }
  </style>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.js"></script>

</head>
<body>

    <form class="url-form clearfix" method="get">
        <label for="url">URL:</label>
        <input type="url" name="url" id="url" value="<?php echo $url; ?>">
        <label>
            <input type="radio" name="show" value="slider" <?php echo !isset($_GET['show']) || $_GET['show'] == 'slider' ? 'checked' : ''; ?>>Show Slider
        </label>
        <label>
            <input type="radio" name="show" value="all" <?php echo isset($_GET['show']) && $_GET['show'] == 'all' ? 'checked' : ''; ?>>Show All
        </label>
        <button type="submit">Run</button>
    </form>

    <?php if (empty($breakpoints)) {
        echo '<p><strong>Breakpoints not found for this site, either this site has none or it restricts this tool from running. Please try a similar tool such as https://chrome.google.com/webstore/detail/emmet-review/epejoicbhllgiimigokgjdoijnpaphdp?hl=en</strong></p>';
    } ?>

    <?php if (!isset($_GET['show']) || $_GET['show'] == 'slider') { ?>
        <ul class="bxslider">
            <?php foreach ($breakpoints as $breakpoint) { ?>
                <li>
                    <div class="iframe-block slider" style="width: <?php echo variBreakpoint($breakpoint, $variAmount); ?>px; height: 550px;">
                        <div class="iframe-block-title"><?php echo $breakpoint[0] . ': ' . $breakpoint[1] . mapBreakpointToDevices($breakpoint); ?></div>
                        <iframe src="<?php echo $url; ?>"></iframe>
                    </div>
                </li>
            <?php } ?>
        </ul>

        <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/bxslider/4.2.5/jquery.bxslider.min.css" />
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bxslider/4.2.5/jquery.bxslider.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bxslider/4.2.5/vendor/jquery.easing.1.3.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bxslider/4.2.5/vendor/jquery.fitvids.js"></script>
        <script>
            (function ($) {
                var slider = $('.bxslider').bxSlider({
                    video: true,
                    useCSS: false,
                    adaptiveHeight: true
                });

                $(document).keydown(function (e) {

                    // don't run this inside the search field
                    if (e.target.nodeName == 'INPUT') {
                        return true;
                    }

                    if (e.keyCode == 39) { // Right arrow 
                        slider.goToNextSlide();
                        return false;
                    } else if (e.keyCode == 37) { // left arrow
                        slider.goToPrevSlide();
                        return false;
                    }
                });
            }(jQuery));
        </script>
    <?php } else { ?>
        <div class="iframe-blocks-container">
            <div class="iframe-blocks-container-inner">
                <?php foreach ($breakpoints as $breakpoint) { ?>
                <div class="iframe-block" style="width:<?php echo variBreakpoint($breakpoint, $variAmount); ?>px">
                    <div class="iframe-block-title"><?php echo $breakpoint[0] . ': ' . $breakpoint[1] . mapBreakpointToDevices($breakpoint); ?></div>
                    <iframe src="<?php echo $url; ?>"></iframe>
                </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <script>
        (function ($) {
            $('.iframe-block').resizable();
        }(jQuery));
    </script>

    </script>

<script>
  (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
  })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

  ga('create', 'UA-32147348-3', 'auto');
  ga('send', 'pageview');

</script>

</body>
</html>

