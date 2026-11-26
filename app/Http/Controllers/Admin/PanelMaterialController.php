<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * @deprecated Panel materials were replaced by MDF sheet stock items (simplify-design-materials). The list answers
 * empty for one release so an admin still running the old build does not error; remove with migration B.
 */
class PanelMaterialController extends Controller
{
    public function index()
    {
        return response()->json(['data' => []]);
    }
}
