import fs from 'fs';
import path from 'path';
import { WorkerLogger } from '../src/logger';

describe('WorkerLogger & Structured File Logging', () => {
  const TEST_LOGS_DIR = path.resolve(__dirname, 'tmp-logs');
  const TEST_LOG_FILE = path.join(TEST_LOGS_DIR, 'test-worker.log');

  beforeAll(() => {
    if (fs.existsSync(TEST_LOGS_DIR)) {
      try {
        fs.rmSync(TEST_LOGS_DIR, { recursive: true, force: true });
      } catch {}
    }
  });

  afterAll(() => {
    try {
      if (fs.existsSync(TEST_LOGS_DIR)) {
        fs.rmSync(TEST_LOGS_DIR, { recursive: true, force: true });
      }
    } catch {}
  });

  test('WorkerLogger.init creates directory and initializes file target', () => {
    WorkerLogger.init(TEST_LOG_FILE);
    expect(WorkerLogger.getLogPath()).toBe(TEST_LOG_FILE);
    expect(fs.existsSync(TEST_LOGS_DIR)).toBe(true);
  });

  test('WorkerLogger writes ISO timestamp, severity levels, tags, and messages', () => {
    WorkerLogger.init(TEST_LOG_FILE);
    WorkerLogger.clear();

    WorkerLogger.debug('TestComponent', 'This is a debug message');
    WorkerLogger.info('Poller', 'Claimed 2 jobs from WordPress');
    WorkerLogger.warn('VideoGenerator', 'Audio duration exceeds limit');
    WorkerLogger.error('Browser', 'Failed to navigate to target URL');

    const logs = WorkerLogger.readLogs();
    expect(logs).toContain('[DEBUG] [TestComponent] This is a debug message');
    expect(logs).toContain('[INFO] [Poller] Claimed 2 jobs from WordPress');
    expect(logs).toContain('[WARN] [VideoGenerator] Audio duration exceeds limit');
    expect(logs).toContain('[ERROR] [Browser] Failed to navigate to target URL');

    // ISO-8601 timestamp regex check
    const lines = logs.split('\n');
    expect(lines.length).toBeGreaterThanOrEqual(4);
    for (const line of lines.slice(0, 4)) {
      expect(line).toMatch(/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\]/);
    }
  });

  test('WorkerLogger serializes object metadata and error instances', () => {
    WorkerLogger.init(TEST_LOG_FILE);
    WorkerLogger.clear();

    const sampleMeta = { post_id: 123, platform: 'tiktok', specs: { duration: 10 } };
    WorkerLogger.info('JobPayload', 'WordPress claim payload', sampleMeta);

    const testError = new Error('Playwright timeout locator error');
    WorkerLogger.error('Poster', 'Automation crashed', testError);

    const logs = WorkerLogger.readLogs();
    expect(logs).toContain('Meta: {"post_id":123,"platform":"tiktok","specs":{"duration":10}}');
    expect(logs).toContain('Error: Playwright timeout locator error');
    expect(logs).toContain('Stack:');
  });

  test('WorkerLogger respects log level filtering', () => {
    WorkerLogger.init(TEST_LOG_FILE);
    WorkerLogger.clear();

    WorkerLogger.setLogLevel('WARN');
    WorkerLogger.debug('IgnoredComponent', 'Debug should be ignored');
    WorkerLogger.info('IgnoredComponent', 'Info should be ignored');
    WorkerLogger.warn('WarningComponent', 'Warn should be included');
    WorkerLogger.error('ErrorComponent', 'Error should be included');

    const logs = WorkerLogger.readLogs();
    expect(logs).not.toContain('Debug should be ignored');
    expect(logs).not.toContain('Info should be ignored');
    expect(logs).toContain('[WARN] [WarningComponent] Warn should be included');
    expect(logs).toContain('[ERROR] [ErrorComponent] Error should be included');

    // Reset log level
    WorkerLogger.setLogLevel('DEBUG');
  });

  test('WorkerLogger.clear empties log file', () => {
    WorkerLogger.init(TEST_LOG_FILE);
    WorkerLogger.info('Tag', 'Message to clear');
    expect(WorkerLogger.readLogs().length).toBeGreaterThan(0);

    WorkerLogger.clear();
    expect(WorkerLogger.readLogs().length).toBe(0);
  });
});
