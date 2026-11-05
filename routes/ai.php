<?php

use App\Mcp\Servers\SparkServer;
use Laravel\Mcp\Facades\Mcp;

/**
 * Spark MCP Server
 *
 * Exposes Spark data to AI agents via the Model Context Protocol.
 * Authentication is required via Sanctum bearer token.
 *
 * Endpoint: /mcp/spark
 */
Mcp::web('/mcp/spark', SparkServer::class)
    ->middleware('auth:sanctum');
