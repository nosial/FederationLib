#!/usr/bin/env python3
"""
LogLib PyServer - A production-ready logging server for LogLib PHP applications.

This server receives structured log events from PHP applications using the LogLib
library over TCP and UDP protocols.
"""

import argparse
import json
import logging
import os
import signal
import socket
import sys
import threading
from datetime import datetime
from enum import Enum
from queue import Queue, Empty
from typing import Optional, Dict, Any, List
import colorama
from colorama import Fore, Style

# Initialize colorama for cross-platform color support
colorama.init(autoreset=True)

# Version information
VERSION = "1.0.0"
PROGRAM_NAME = "LogLib PyServer"


class LogLevel(str, Enum):
    """Log level enumeration matching LogLib PHP constants."""
    DEBUG = "DBG"
    VERBOSE = "VRB" 
    INFO = "INFO"
    WARNING = "WRN"
    ERROR = "ERR"
    CRITICAL = "CRT"

    @classmethod
    def to_python_level(cls, level: str) -> int:
        """Convert LogLib level to Python logging level."""
        try:
            # Try to convert string to LogLevel enum first
            log_level = cls(level)
            level_map = {
                cls.DEBUG: logging.DEBUG,
                cls.VERBOSE: logging.DEBUG,
                cls.INFO: logging.INFO,
                cls.WARNING: logging.WARNING,
                cls.ERROR: logging.ERROR,
                cls.CRITICAL: logging.CRITICAL
            }
            return level_map.get(log_level, logging.INFO)
        except ValueError:
            return logging.INFO

    @classmethod
    def get_color(cls, level: str) -> str:
        """Get ANSI color code for log level."""
        try:
            # Try to convert string to LogLevel enum first
            log_level = cls(level)
            color_map = {
                cls.DEBUG: Fore.CYAN,
                cls.VERBOSE: Fore.BLUE,
                cls.INFO: Fore.GREEN,
                cls.WARNING: Fore.YELLOW,
                cls.ERROR: Fore.RED,
                cls.CRITICAL: Fore.MAGENTA
            }
            return color_map.get(log_level, Fore.WHITE)
        except ValueError:
            return Fore.WHITE


class CallType(str, Enum):
    """Call type enumeration matching LogLib PHP constants."""
    STATIC_CALL = "::"
    INSTANCE_CALL = "->"
    FUNCTION_CALL = ""


class StackTrace:
    """Represents a stack trace frame compatible with LogLib PHP structure."""
    
    def __init__(self, file: Optional[str] = None, line: Optional[int] = None,
                 function: Optional[str] = None, args: Optional[List[Any]] = None,
                 class_name: Optional[str] = None, call_type: Optional[str] = None):
        self.file = file
        self.line = line
        self.function = function
        self.args = args if args else None
        self.class_name = class_name
        self.call_type = call_type or "::"

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'StackTrace':
        """Create StackTrace from dictionary data."""
        return cls(
            file=data.get('file'),
            line=int(data['line']) if data.get('line') is not None else None,
            function=data.get('function'),
            args=data.get('args'),
            class_name=data.get('class'),
            call_type=data.get('call_type', '::')
        )

    def format_location(self) -> str:
        """Format file and line information."""
        if self.file and self.line:
            return f"{self.file}:{self.line}"
        elif self.file:
            return self.file
        return "unknown"

    def format_call(self) -> str:
        """Format function call information."""
        if self.class_name and self.function:
            return f"{self.class_name}{self.call_type}{self.function}()"
        elif self.function:
            return f"{self.function}()"
        return "unknown"

    def format(self, indent: str = "  ") -> str:
        """Format stack trace frame for display."""
        location = f"{Fore.CYAN}{self.format_location()}{Style.RESET_ALL}"
        call = f"{Fore.BLUE}{self.format_call()}{Style.RESET_ALL}"
        return f"{indent}at {call} in {location}"


class ExceptionDetails:
    """Represents exception details compatible with LogLib PHP structure."""
    
    def __init__(self, name: str, message: str, code: Optional[int] = None,
                 file: Optional[str] = None, line: Optional[int] = None,
                 trace: Optional[List[StackTrace]] = None, 
                 previous: Optional['ExceptionDetails'] = None):
        self.name = name
        self.message = message
        self.code = code
        self.file = file
        self.line = line
        self.trace = trace if trace else []
        self.previous = previous

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> Optional['ExceptionDetails']:
        """Create ExceptionDetails from dictionary data."""
        if not data:
            return None

        trace = []
        if 'trace' in data and isinstance(data['trace'], list):
            trace = [StackTrace.from_dict(frame) for frame in data['trace']
                     if isinstance(frame, dict)]

        previous = None
        if 'previous' in data and isinstance(data['previous'], dict):
            previous = cls.from_dict(data['previous'])

        return cls(
            name=data.get('name', ''),
            message=data.get('message', ''),
            code=int(data['code']) if data.get('code') is not None else None,
            file=data.get('file'),
            line=int(data['line']) if data.get('line') is not None else None,
            trace=trace,
            previous=previous
        )

    def format(self, level: int = 0) -> str:
        """Format exception for display with proper indentation."""
        indent = "  " * level
        parts = []

        # Exception header
        header = f"{indent}{Fore.RED}{self.name}{Style.RESET_ALL}"
        if self.code is not None:
            header += f" ({self.code})"
        header += f": {self.message}"
        
        # Location information
        if self.file and self.line:
            header += f" at {Fore.CYAN}{self.file}:{self.line}{Style.RESET_ALL}"
        
        parts.append(header)

        # Stack trace
        if self.trace:
            parts.append(f"{indent}Stack trace:")
            for frame in self.trace:
                parts.append(frame.format(indent + "  "))

        # Previous exception
        if self.previous:
            parts.append(f"{indent}Caused by:")
            parts.append(self.previous.format(level + 1))

        return "\n".join(parts)


class LogFormatter:
    """Handles formatting of log events for display."""
    
    def __init__(self, show_timestamp: bool = True, show_address: bool = False, 
                 show_app_name: bool = True, colorize: bool = True):
        self.show_timestamp = show_timestamp
        self.show_address = show_address
        self.show_app_name = show_app_name
        self.colorize = colorize

    def format_timestamp(self, timestamp: int) -> str:
        """Format timestamp for display."""
        try:
            dt = datetime.fromtimestamp(timestamp)
            return dt.strftime('%Y-%m-%d %H:%M:%S')
        except (ValueError, TypeError, OSError):
            return datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    def format_log_event(self, data: Dict[str, Any], address: tuple) -> str:
        """Format a complete log event for display."""
        parts = []
        
        # Timestamp
        if self.show_timestamp:
            timestamp = data.get('timestamp', int(datetime.now().timestamp()))
            formatted_time = self.format_timestamp(timestamp)
            parts.append(formatted_time)
        
        # Log level with color
        level = data.get('level', 'INFO')
        if self.colorize:
            color = LogLevel.get_color(level)
            level_str = f"{color}[{level}]{Style.RESET_ALL}"
        else:
            level_str = f"[{level}]"
        parts.append(level_str)
        
        # Application name
        if self.show_app_name:
            app_name = data.get('application_name', 'Unknown')
            if self.colorize:
                app_str = f"{Fore.MAGENTA}[{app_name}]{Style.RESET_ALL}"
            else:
                app_str = f"[{app_name}]"
            parts.append(app_str)
        
        # Address
        if self.show_address:
            addr_str = f"[{address[0]}:{address[1]}]"
            parts.append(addr_str)
        
        # Message
        message = data.get('message', '')
        parts.append(message)
        
        result = " ".join(parts)
        
        # Exception details
        exception_data = data.get('exception')
        if exception_data:
            exception = ExceptionDetails.from_dict(exception_data)
            if exception:
                result += f"\n{exception.format()}"
        return result


class LogLibPyServer:
    """Production-ready logging server for LogLib PHP applications."""
    
    def __init__(self, host: str, port: int, working_directory: str,
                 formatter: LogFormatter, buffer_size: int = 65535,
                 max_connections: int = 100):
        self.host = host
        self.port = port
        self.working_directory = working_directory
        self.formatter = formatter
        self.buffer_size = min(buffer_size, 65535)  # UDP limit
        self.max_connections = max_connections
        
        # Thread management
        self.stop_event = threading.Event()
        self.log_queue: Queue = Queue()
        
        # File logging
        self.current_date = datetime.now().strftime('%Y-%m-%d')
        self.log_file = None
        self.log_file_lock = threading.Lock()
        
        # Statistics
        self.stats = {
            'tcp_connections': 0,
            'udp_packets': 0,
            'events_processed': 0,
            'errors': 0
        }
        self.stats_lock = threading.Lock()
        
        # Setup
        self._setup_directories()
        self._setup_logging()
        self._setup_signal_handlers()

    def _setup_directories(self):
        """Create necessary directories."""
        try:
            os.makedirs(self.working_directory, exist_ok=True)
        except OSError as e:
            print(f"Error creating directory {self.working_directory}: {e}", file=sys.stderr)
            sys.exit(1)

    def _setup_logging(self):
        """Setup internal logging."""
        self.logger = logging.getLogger(PROGRAM_NAME)
        self.logger.setLevel(logging.INFO)
        
        if not self.logger.handlers:
            handler = logging.StreamHandler()
            if self.formatter.colorize:
                format_str = f'%(asctime)s {Fore.WHITE}[%(levelname)s]{Style.RESET_ALL} %(message)s'
            else:
                format_str = '%(asctime)s [%(levelname)s] %(message)s'
            
            formatter = logging.Formatter(format_str, datefmt='%Y-%m-%d %H:%M:%S')
            handler.setFormatter(formatter)
            self.logger.addHandler(handler)

    def _setup_signal_handlers(self):
        """Setup signal handlers for graceful shutdown."""
        def signal_handler(signum, frame):
            self.logger.info("Received shutdown signal, stopping server...")
            self.stop()
        
        signal.signal(signal.SIGINT, signal_handler)
        signal.signal(signal.SIGTERM, signal_handler)

    def _update_stats(self, stat_name: str, increment: int = 1):
        """Thread-safe statistics update."""
        with self.stats_lock:
            self.stats[stat_name] += increment

    def _get_log_file(self):
        """Get current log file handle with date rotation."""
        with self.log_file_lock:
            current_date = datetime.now().strftime('%Y-%m-%d')
            if current_date != self.current_date or self.log_file is None:
                if self.log_file:
                    self.log_file.close()
                
                self.current_date = current_date
                filename = os.path.join(self.working_directory, f"loglib-{current_date}.jsonl")
                try:
                    self.log_file = open(filename, 'a', encoding='utf-8')
                except OSError as e:
                    self.logger.error(f"Failed to open log file {filename}: {e}")
                    self._update_stats('errors')
                    return None
            
            return self.log_file

    def _log_writer(self):
        """Background thread for writing logs to file."""
        while not self.stop_event.is_set() or not self.log_queue.empty():
            try:
                data = self.log_queue.get(timeout=1)
                log_file = self._get_log_file()
                if log_file:
                    json.dump(data, log_file, ensure_ascii=False)
                    log_file.write('\n')
                    log_file.flush()
            except Empty:
                continue
            except Exception as e:
                self.logger.error(f"Error writing to log file: {e}")
                self._update_stats('errors')

    def _handle_log_event(self, data: Dict[str, Any], address: tuple) -> None:
        """Process and display a structured log event."""
        try:
            # Validate required fields
            if not isinstance(data, dict):
                raise ValueError("Invalid data structure")
            
            # Format and display
            formatted_message = self.formatter.format_log_event(data, address)
            level = data.get('level', 'INFO')
            python_level = LogLevel.to_python_level(level)
            
            # Log to console
            self.logger.log(python_level, formatted_message)
            
            # Queue for file logging
            self.log_queue.put({
                "timestamp": datetime.now().isoformat(),
                "address": f"{address[0]}:{address[1]}",
                "data": data
            })
            
            self._update_stats('events_processed')
            
        except Exception as e:
            self.logger.error(f"Error processing log event from {address}: {e}")
            self._update_stats('errors')

    def _handle_data(self, data: bytes, address: tuple) -> None:
        """Process incoming data and attempt to parse as JSON."""
        try:
            if not data:
                return
            
            decoded_data = data.decode('utf-8').strip()
            if not decoded_data:
                return
            
            try:
                json_data = json.loads(decoded_data)
                self._handle_log_event(json_data, address)
            except json.JSONDecodeError as e:
                # Log non-JSON data as info
                self.logger.info(f"Non-JSON data from {address}: {decoded_data[:100]}{'...' if len(decoded_data) > 100 else ''}")
                self.log_queue.put({
                    "timestamp": datetime.now().isoformat(),
                    "address": f"{address[0]}:{address[1]}",
                    "raw_data": decoded_data
                })
                
        except UnicodeDecodeError as e:
            self.logger.warning(f"Invalid UTF-8 data from {address}: {e}")
            self._update_stats('errors')
        except Exception as e:
            self.logger.error(f"Data handling error from {address}: {e}")
            self._update_stats('errors')

    def _handle_tcp_client(self, client_socket, address):
        """Handle TCP client connection."""
        self.logger.debug(f"TCP connection from {address}")
        self._update_stats('tcp_connections')
        
        try:
            with client_socket:
                client_socket.settimeout(30.0)  # 30 second timeout
                buffer = b""
                
                while not self.stop_event.is_set():
                    try:
                        chunk = client_socket.recv(self.buffer_size)
                        if not chunk:
                            break
                        
                        buffer += chunk
                        
                        # Process complete messages (assuming newline-delimited)
                        while b'\n' in buffer:
                            line, buffer = buffer.split(b'\n', 1)
                            if line.strip():
                                self._handle_data(line.strip(), address)
                                
                    except socket.timeout:
                        continue
                    except socket.error:
                        break
                
                # Process any remaining data
                if buffer.strip():
                    self._handle_data(buffer.strip(), address)
                    
        except Exception as e:
            self.logger.error(f"TCP client error from {address}: {e}")
            self._update_stats('errors')
        finally:
            self.logger.debug(f"TCP connection closed from {address}")

    def _start_tcp_server(self):
        """Start TCP server."""
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as tcp_socket:
                tcp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                tcp_socket.bind((self.host, self.port))
                tcp_socket.listen(self.max_connections)
                tcp_socket.settimeout(1.0)  # Non-blocking accept
                
                self.logger.info(f"TCP server listening on {self.host}:{self.port}")
                
                while not self.stop_event.is_set():
                    try:
                        client_socket, address = tcp_socket.accept()
                        thread = threading.Thread(
                            target=self._handle_tcp_client,
                            args=(client_socket, address),
                            daemon=True
                        )
                        thread.start()
                    except socket.timeout:
                        continue
                    except Exception as e:
                        if not self.stop_event.is_set():
                            self.logger.error(f"TCP server error: {e}")
                            self._update_stats('errors')
                        
        except Exception as e:
            self.logger.error(f"Failed to start TCP server: {e}")
            self._update_stats('errors')

    def _start_udp_server(self):
        """Start UDP server."""
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp_socket:
                udp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                udp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 1024 * 1024)
                udp_socket.bind((self.host, self.port))
                udp_socket.settimeout(1.0)  # Non-blocking receive
                
                self.logger.info(f"UDP server listening on {self.host}:{self.port}")
                
                while not self.stop_event.is_set():
                    try:
                        data, address = udp_socket.recvfrom(self.buffer_size)
                        self._update_stats('udp_packets')
                        self._handle_data(data, address)
                    except socket.timeout:
                        continue
                    except Exception as e:
                        if not self.stop_event.is_set():
                            self.logger.error(f"UDP server error: {e}")
                            self._update_stats('errors')
                            
        except Exception as e:
            self.logger.error(f"Failed to start UDP server: {e}")
            self._update_stats('errors')

    def start(self):
        """Start the logging server."""
        self.logger.info(f"Starting {PROGRAM_NAME} v{VERSION}")
        self.logger.info(f"Log directory: {os.path.abspath(self.working_directory)}")
        
        # Start background log writer
        log_writer_thread = threading.Thread(target=self._log_writer, daemon=True)
        log_writer_thread.start()
        
        # Start protocol servers
        tcp_thread = threading.Thread(target=self._start_tcp_server, daemon=True)
        udp_thread = threading.Thread(target=self._start_udp_server, daemon=True)
        
        tcp_thread.start()
        udp_thread.start()
        
        try:
            while not self.stop_event.is_set():
                tcp_thread.join(timeout=1)
                udp_thread.join(timeout=1)
                
                if not tcp_thread.is_alive() and not udp_thread.is_alive():
                    break
                    
        except KeyboardInterrupt:
            pass
        finally:
            self.stop()

    def stop(self):
        """Stop the logging server gracefully."""
        if self.stop_event.is_set():
            return
        
        self.logger.info("Shutting down server...")
        self.stop_event.set()
        
        # Close log file
        with self.log_file_lock:
            if self.log_file:
                self.log_file.close()
                self.log_file = None
        
        # Print final statistics
        with self.stats_lock:
            self.logger.info(f"Final statistics: {dict(self.stats)}")
        
        self.logger.info("Server stopped")


def create_argument_parser():
    """Create and configure argument parser."""
    parser = argparse.ArgumentParser(
        prog=PROGRAM_NAME.lower().replace(' ', '-'),
        description=f"{PROGRAM_NAME} - A logging server for LogLib PHP applications",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=f"""
Examples:
  {PROGRAM_NAME.lower().replace(' ', '-')} --port 8080
  {PROGRAM_NAME.lower().replace(' ', '-')} --host 0.0.0.0 --port 9999 --no-color
  {PROGRAM_NAME.lower().replace(' ', '-')} --working-directory /var/log/loglib
        """
    )
    
    parser.add_argument(
        '--version', action='version', version=f'{PROGRAM_NAME} {VERSION}'
    )
    
    parser.add_argument(
        '-H', '--host', type=str, default='0.0.0.0',
        help='Host address to bind to (default: 0.0.0.0)'
    )
    
    parser.add_argument(
        '-p', '--port', type=int, default=8080,
        help='Port to listen on (default: 8080)'
    )
    
    parser.add_argument(
        '-w', '--working-directory', type=str, default='./logs',
        help='Directory to store log files (default: ./logs)'
    )
    
    parser.add_argument(
        '--buffer-size', type=int, default=65535,
        help='Maximum buffer size for UDP packets (default: 65535)'
    )
    
    parser.add_argument(
        '--max-connections', type=int, default=10,
        help='Maximum concurrent TCP connections (default: 10)'
    )
    
    # Display options
    display_group = parser.add_argument_group('display options')
    
    display_group.add_argument(
        '--no-color', action='store_true',
        help='Disable colored output'
    )
    
    display_group.add_argument(
        '--no-timestamp', action='store_true',
        help='Hide timestamps in console output'
    )
    
    display_group.add_argument(
        '--show-address', action='store_true',
        help='Show client address in console output'
    )
    
    display_group.add_argument(
        '--no-app-name', action='store_true',
        help='Hide application names in console output'
    )
    
    # Debug options
    debug_group = parser.add_argument_group('debug options')
    
    debug_group.add_argument(
        '-v', '--verbose', action='store_true',
        help='Enable verbose logging'
    )
    
    debug_group.add_argument(
        '-q', '--quiet', action='store_true',
        help='Suppress non-error output'
    )
    
    return parser


def validate_arguments(args):
    """Validate command line arguments."""
    errors = []
    
    if args.port < 1 or args.port > 65535:
        errors.append("Port must be between 1 and 65535")
    
    if args.buffer_size < 1024 or args.buffer_size > 65535:
        errors.append("Buffer size must be between 1024 and 65535")
    
    if args.max_connections < 1 or args.max_connections > 1000:
        errors.append("Max connections must be between 1 and 1000")
    
    if args.verbose and args.quiet:
        errors.append("Cannot specify both --verbose and --quiet")
    
    if errors:
        for error in errors:
            print(f"Error: {error}", file=sys.stderr)
        return False
    
    return True


def main():
    """Main entry point."""
    parser = create_argument_parser()
    args = parser.parse_args()
    
    if not validate_arguments(args):
        sys.exit(1)
    
    # Create formatter
    formatter = LogFormatter(
        show_timestamp=not args.no_timestamp,
        show_address=args.show_address,
        show_app_name=not args.no_app_name,
        colorize=not args.no_color
    )
    
    # Create and configure server
    server = LogLibPyServer(
        host=args.host,
        port=args.port,
        working_directory=args.working_directory,
        formatter=formatter,
        buffer_size=args.buffer_size,
        max_connections=args.max_connections
    )
    
    # Set logging level
    if args.quiet:
        server.logger.setLevel(logging.ERROR)
    elif args.verbose:
        server.logger.setLevel(logging.DEBUG)
    else:
        server.logger.setLevel(logging.INFO)
    
    # Start server
    try:
        server.start()
    except Exception as e:
        print(f"Fatal error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()