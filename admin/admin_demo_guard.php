<?php
function guardDemoAdmin() {
    if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        return false;
    }
    return true;
}