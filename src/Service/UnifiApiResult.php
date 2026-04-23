<?php

namespace Drupal\unifi_access_sync\Service;

/**
 * Structured result of a UniFi Access API call.
 *
 * Returned by UnifiApiService methods instead of null-or-data so callers
 * can distinguish a real empty result from a failed call, and can log the
 * actual failure reason (HTTP status, response body, exception message)
 * rather than the bare "something went wrong" they'd have to infer from
 * a NULL return.
 *
 * Not marked final: PHPUnit's auto-return-double needs to subclass the
 * return type of mocked methods. A doubled instance gets default property
 * values (ok = FALSE), which is the safe behavior — any code path that
 * forgets to mock listUsers() sees a "failed" result and aborts rather
 * than silently succeeding with NULL-ish data.
 */
class UnifiApiResult {

  public bool $ok = FALSE;
  public mixed $data = NULL;
  public ?int $statusCode = NULL;
  public ?string $errorMessage = NULL;
  public ?string $responseBody = NULL;

  public static function success(mixed $data = NULL, ?int $statusCode = NULL): self {
    $r = new self();
    $r->ok = TRUE;
    $r->data = $data;
    $r->statusCode = $statusCode;
    return $r;
  }

  public static function failure(
    string $errorMessage,
    ?int $statusCode = NULL,
    ?string $responseBody = NULL,
  ): self {
    $r = new self();
    $r->ok = FALSE;
    $r->errorMessage = $errorMessage;
    $r->statusCode = $statusCode;
    $r->responseBody = $responseBody;
    return $r;
  }

  /**
   * Single-line human-readable description, suitable for log messages.
   */
  public function describe(): string {
    if ($this->ok) {
      return 'ok';
    }
    $parts = [];
    if ($this->statusCode !== NULL) {
      $parts[] = "HTTP {$this->statusCode}";
    }
    if ($this->errorMessage !== NULL && $this->errorMessage !== '') {
      $parts[] = $this->errorMessage;
    }
    if ($this->responseBody !== NULL && $this->responseBody !== '') {
      $parts[] = "body: {$this->responseBody}";
    }
    return $parts ? implode(' — ', $parts) : 'unknown failure';
  }

}
