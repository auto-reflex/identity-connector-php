<?php

namespace AutoGteck\IdentityConnector\Http\Controllers;

use AutoGteck\IdentityConnector\Web\WebLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebLoginController
{
    public function __invoke(Request $request, WebLogin $login): RedirectResponse
    {
        return $login->redirect($request);
    }
}
