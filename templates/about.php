<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | About</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
</head>
<body>
    <h1>About</h1>
    <p>
        YTAN is a system designed to help plan kayaking trips. Over the years, it has been continuously developed and improved.
    </p>
    <p>
        Some of the information stored in the system has not been verified, but much of it is based on trips that have already been paddled.
        No guarantee is made as to the accuracy of the data.
        Especially when out on the water, you must always be very careful and should never blindly rely on information provided by others.
    </p>
    <p>
        Some of the information shown here is based on the documents
        "<a href="https://tjornkajak.se/paddla-kajak-och-talta-i-bohuslan-haftena" target="_blank">Sea kayaking and camping in Bohuslän Part A to C</a>"
        by <a href="https://tjornkajak.se/jens-marklund/" target="_blank">Jens Marklund</a>.
        These highly recommended documents can be downloaded from the website of <a href="https://tjornkajak.se/" target="_blank">Tjörns Kajakklubb</a>.
        Many thanks to Jens and his friends for this great work.
    </p>
</body>
</html>
