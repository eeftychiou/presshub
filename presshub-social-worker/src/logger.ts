import fs from 'fs';
import path from 'path';

export type LogLevel = 'DEBUG' | 'INFO' | 'WARN' | 'ERROR';

const LOG_LEVEL_PRIORITY: Record<LogLevel, number> = {
  DEBUG: 0,
  INFO: 1,
  WARN: 2,
  ERROR: 3
};

/**
 * WorkerLogger
 * Production logging facility writing structured logs with ISO timestamps,
 * severity levels, and component tags to both console and logs/worker.log file.
 */
export class WorkerLogger {
  private static logFilePath: string | null = null;
  private static minLevel: LogLevel = 'DEBUG';
  private static consoleEnabled: boolean = true;
  private static isInitialized: boolean = false;

  /**
   * Initializes the logger, ensuring logs directory exists and paths are resolved.
   */
  public static init(customPath?: string): void {
    const defaultLogDir = fs.existsSync(path.resolve(process.cwd(), 'presshub-social-worker/logs'))
      ? path.resolve(process.cwd(), 'presshub-social-worker/logs')
      : path.resolve(__dirname, '../logs');
    const defaultLogPath = path.join(defaultLogDir, 'worker.log');

    const configuredPath = customPath || process.env.PRESSHUB_LOG_FILE || defaultLogPath;
    this.logFilePath = path.resolve(configuredPath);

    const logDir = path.dirname(this.logFilePath);
    if (!fs.existsSync(logDir)) {
      try {
        fs.mkdirSync(logDir, { recursive: true });
      } catch (err: any) {
        console.warn(`[WorkerLogger] Failed to create log directory (${logDir}):`, err.message);
      }
    }

    if (process.env.PRESSHUB_LOG_LEVEL) {
      const envLevel = process.env.PRESSHUB_LOG_LEVEL.toUpperCase() as LogLevel;
      if (LOG_LEVEL_PRIORITY[envLevel] !== undefined) {
        this.minLevel = envLevel;
      }
    }

    if (process.env.PRESSHUB_LOG_CONSOLE !== undefined) {
      this.consoleEnabled = process.env.PRESSHUB_LOG_CONSOLE.toLowerCase() !== 'false';
    }

    this.isInitialized = true;
  }

  /**
   * Returns the absolute path of the active log file.
   */
  public static getLogPath(): string {
    if (!this.isInitialized || !this.logFilePath) {
      this.init();
    }
    return this.logFilePath!;
  }

  /**
   * Sets the minimum log level.
   */
  public static setLogLevel(level: LogLevel): void {
    this.minLevel = level;
  }

  /**
   * Returns current minimum log level.
   */
  public static getLogLevel(): LogLevel {
    return this.minLevel;
  }

  /**
   * Enables or disables console output.
   */
  public static setConsoleEnabled(enabled: boolean): void {
    this.consoleEnabled = enabled;
  }

  /**
   * Clears the current log file contents.
   */
  public static clear(): void {
    const targetPath = this.getLogPath();
    try {
      if (fs.existsSync(targetPath)) {
        fs.writeFileSync(targetPath, '', 'utf-8');
      }
    } catch (err: any) {
      console.warn(`[WorkerLogger] Failed to clear log file:`, err.message);
    }
  }

  /**
   * Reads recent logs from the log file.
   */
  public static readLogs(maxLines: number = 200): string {
    const targetPath = this.getLogPath();
    try {
      if (!fs.existsSync(targetPath)) {
        return '';
      }
      const content = fs.readFileSync(targetPath, 'utf-8');
      if (!maxLines || maxLines <= 0) {
        return content;
      }
      const lines = content.split(/\r?\n/).filter((l) => l.trim().length > 0);
      return lines.slice(-maxLines).join('\n');
    } catch {
      return '';
    }
  }

  /**
   * Logs a message with a specific severity level, component tag, and optional metadata.
   */
  public static log(level: LogLevel, tag: string, message: string, meta?: any): void {
    if (LOG_LEVEL_PRIORITY[level] < LOG_LEVEL_PRIORITY[this.minLevel]) {
      return;
    }

    const isoTime = new Date().toISOString();
    let formattedMeta = '';

    if (meta !== undefined && meta !== null) {
      try {
        if (meta instanceof Error) {
          formattedMeta = ` | Error: ${meta.message}${meta.stack ? `\nStack: ${meta.stack}` : ''}`;
        } else if (typeof meta === 'object') {
          formattedMeta = ` | Meta: ${JSON.stringify(meta)}`;
        } else {
          formattedMeta = ` | Meta: ${String(meta)}`;
        }
      } catch {
        formattedMeta = ' | Meta: [Unserializable]';
      }
    }

    const logLine = `[${isoTime}] [${level}] [${tag}] ${message}${formattedMeta}`;

    // 1. Console Output
    if (this.consoleEnabled) {
      if (level === 'ERROR') {
        console.error(logLine);
      } else if (level === 'WARN') {
        console.warn(logLine);
      } else {
        console.log(logLine);
      }
    }

    // 2. File Output
    const targetPath = this.getLogPath();
    try {
      fs.appendFileSync(targetPath, logLine + '\n', 'utf-8');
    } catch {
      // Failsafe: never throw if file write fails
    }
  }

  public static debug(tag: string, message: string, meta?: any): void {
    this.log('DEBUG', tag, message, meta);
  }

  public static info(tag: string, message: string, meta?: any): void {
    this.log('INFO', tag, message, meta);
  }

  public static warn(tag: string, message: string, meta?: any): void {
    this.log('WARN', tag, message, meta);
  }

  public static error(tag: string, message: string, meta?: any): void {
    this.log('ERROR', tag, message, meta);
  }
}
