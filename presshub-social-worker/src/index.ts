import { createApp } from './app';

const PORT = parseInt(process.env.PRESSHUB_WORKER_PORT || '3456', 10);
const SECRET = process.env.PRESSHUB_WORKER_SECRET;

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

// Handle graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received. Shutting down gracefully...');
  server.close(() => {
    process.exit(0);
  });
});

process.on('SIGINT', () => {
  console.log('SIGINT received. Shutting down gracefully...');
  server.close(() => {
    process.exit(0);
  });
});
