<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;

/**
 * Base controller all API controllers extend.
 *
 * Thin by design: validation is delegated to Form Requests, business logic to
 * Services, and response formatting to the {@see ApiResponse} trait. This keeps
 * controllers as small adapters between HTTP input and the service layer.
 */
abstract class Controller
{
    use ApiResponse;
}
