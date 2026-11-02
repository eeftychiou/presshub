import { loadEnv, getWorkerConfig } from './config';
import { createApp } from './app';
import { PressHubPoller } from './poller';

// Load .env file at startup
loadEnv();

let activePoller: PressHubPoller | undefined;

/**
 * Starts the HTTP server and optionally initializes Pull Mode poller if configured.
 */
export function startServer() {
  const config = getWorkerConfig();
  const PORT = config.port;
  const SECRET = config.workerSecret;
  const WP_URL = config.wpUrl;

  if (!SECRET) {
    console.warn('⚠️  WARNING: PRESSHUB_WORKER_SECRET environment variable is not set. All authenticated API requests will fail.');
  }

  const app = createApp({
    secret: () => process.env.PRESSHUB_WORKER_SECRET
  });

  const server = app.listen(PORT, () => {
    console.log(`🚀 PressHub Social Media Worker running on port ${PORT}`);
    console.log(`📡 Health Check endpoint: http://localhost:${PORT}/health`);
  });

  // Pull Mode: If WordPress URL and Worker Secret are configured, start poller
  if (WP_URL && SECRET) {
    activePoller = new PressHubPoller({
      wpUrl: WP_URL,
      workerSecret: SECRET,
      pollIntervalMs: config.pollIntervalMs
    });
    activePoller.start();
  } else {
    console.log('ℹ️  Pull Mode idle (PRESSHUB_WP_URL or PRESSHUB_WORKER_SECRET not set). Running in Push Mode.');
  }

  // Graceful shutdown handlers
  const shutdown = () => {
    console.log('Shutting down gracefully...');
    if (activePoller) {
      activePoller.stop();
    }
    server.close(() => {
      process.exit(0);
    });
  };

  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);

  return { app, server, poller: activePoller };
}

if (require.main === module) {
  startServer();
}
