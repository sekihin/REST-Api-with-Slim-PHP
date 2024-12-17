<?php

$app->get('/hello/{name}', function ($request, $response, $args) {
    $data = array('message' => 'Hello, ' . $args['name']);
    return $response->withJson($data);
});

?>