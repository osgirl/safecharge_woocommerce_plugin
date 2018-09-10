<?php

session_start();

include("../token.php");

if (!checkToken($_POST["t"], $_POST["token"])) {
    die('no token generated');
}

$_SESSION['sc_country'] =  $_POST["country"];

?>