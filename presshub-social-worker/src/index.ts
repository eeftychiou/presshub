import { loadEnv, getWorkerConfig } from './config';
import { createApp } from './app';
import { PressHubPoller } from './poller';
import { WorkerLogger } from './logger';

export { WorkerLogger } from './logger';

// Load .env file at startup
loadEnv();
WorkerLogger.init();

let activePoller: PressHubPoller | undefined;

/**
 * Starts the HTTP server and optionally initializes Pull Mode poller if configured.
 */
export function startServer() {
  const config = getWorkerConfig();
  const PORT = config.port;
  const SECRET = config.workerSecret;
  const WP_URL = config.wpUrl;

  WorkerLogger.info('Server', `Log file initialized at: ${WorkerLogger.getLogPath()}`);

  if (!SECRET) {
    WorkerLogger.warn('Server', 'PRESSHUB_WORKER_SECRET environment variable is not set. All authenticated API requests will fail.');
  }

  const app = createApp({
    secret: () => process.env.PRESSHUB_WORKER_SECRET
  });

  const server = app.listen(PORT, () => {
    WorkerLogger.info('Server', `🚀 PressHub Social Media Worker running on port ${PORT}`);
    WorkerLogger.info('Server', `📡 Health Check endpoint: http://localhost:${PORT}/health`);
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
    WorkerLogger.info('Server', 'ℹ️  Pull Mode idle (PRESSHUB_WP_URL or PRESSHUB_WORKER_SECRET not set). Running in Push Mode.');
  }

  // Uncaught exceptions and unhandled rejections handling
  process.on('uncaughtException', (err) => {
    WorkerLogger.error('Server', `Uncaught Exception: ${err.message}`, { stack: err.stack });
  });
  process.on('unhandledRejection', (reason: any) => {
    WorkerLogger.error('Server', `Unhandled Rejection: ${reason?.message || reason}`, { stack: reason?.stack });
  });

  // Graceful shutdown handlers
  const shutdown = () => {
    WorkerLogger.info('Server', 'Shutting down gracefully...');
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
