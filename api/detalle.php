<?php
declare(strict_types=1);

/* ============================================================
   api/detalle.php?id=XXX — ficha + descargas (rate-limit estricto)
   El payload vive en inc/detalle_api.php (también lo usa el build
   estático para generar datos/fuentes/<id>.json).
   ============================================================ */

require dirname(__DIR__) . '/inc/detalle_api.php';

$rl = rate_limit('detalle', RL_MAX_DETALLE);
if (!$rl['ok']) json_out(['error' => 'too_many_requests', 'retry' => RL_VENTANA], 429);

$id = strtoupper((string)($_GET['id'] ?? ''));
if (!id_valido($id)) json_out(['error' => 'bad_id'], 400);

$payload = detalle_payload($id);
if ($payload === null) json_out(['error' => 'not_found'], 404);

json_out($payload);
