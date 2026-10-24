<?php
use Baja\Model\EventoQuery;
use Baja\Results\EventStatus;
use Baja\Results\ViewerContext;

// The judges' copy of a results page, linked from the judge menu
// (Template::printMenu with $juiz). It is prova.php with two differences:
//
//   - the viewer is the forum user: a user named in the resultado's
//     "authorized_users", or a developer, sees past spoilers;
//   - the menu links to the judges' copies, and the page is not cached
//     publicly (prova.php only sends Cache-Control when $judgeViewer is
//     not set).
//
// $user and $_DEV_MODE come from src/bootstrap.php: nginx does not set
// SKIP_AUTH for this file, so the forum session is loaded.
$judgeViewer = ViewerContext::authorized(
    EventStatus::of(EventoQuery::getCurrentEvent()),
    $user->data['username'] ?? null,
    $_DEV_MODE
);
$judgeMenu = true;

require __DIR__ . '/prova.php';
