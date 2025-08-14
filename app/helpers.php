<?php
// app/helpers.php (create this file and add to composer.json autoload)

if (!function_exists('successResponse')) {
    function successResponse($message, $data = null, $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}

if (!function_exists('queryErrorResponse')) {
    function queryErrorResponse($message = 'Failed to execute query', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('serverErrorResponse')) {
    function serverErrorResponse($message = 'An unexpected server error occurred', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('validationErrorResponse')) {
    function validationErrorResponse($errors, $message = 'Validation failed', $statusCode = 422)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }
}

if (!function_exists('createdResponse')) {
    function createdResponse($data = null, string $message = 'Resource created successfully', int $code = 201)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse($message, $statusCode = 500, $error = null)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('updatedResponse')) {
    function updatedResponse($data, string $message = 'Resource updated successfully', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}

if (!function_exists('deleteResponse')) {
    function deleteResponse(string $message = 'Resource deleted successfully', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
        ], $code);
    }
}

if (!function_exists('notFoundResponse')) {
    function notFoundResponse($message = 'Resource not found', $statusCode = 404)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'data' => []
        ], $statusCode);
    }
}

if (!function_exists('forbiddenResponse')) {
    function forbiddenResponse($message = 'Access forbidden', $statusCode = 403)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
        ], $statusCode);
    }
}

if (!function_exists('paginatedResponse')) {
    function paginatedResponse($data, $message = 'Data retrieved successfully', $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
                'has_more_pages' => $data->hasMorePages(),
            ]
        ], $statusCode);
    }
}
