import argparse
import json
import logging
import os
import socket
import threading
from datetime import datetime
from queue import Queue, Empty
from enum import Enum
from typing import Optional, Dict, Any, List
from dataclasses import dataclass
import colorama
from colorama import Fore, Back, Style

# Initialize colorama for cross-platform color support
colorama.init()


class LogLevel(str, Enum):
    DEBUG = "DBG"
    VERBOSE = "VRB"
    INFO = "INFO"
    WARNING = "WRN"
    ERROR = "ERR"
    CRITICAL = "CRT"

    @classmethod
    def to_python_level(cls, level: str) -> int:
        return {
            cls.DEBUG: logging.DEBUG,
            cls.VERBOSE: logging.DEBUG,
            cls.INFO: logging.INFO,
            cls.WARNING: logging.WARNING,
            cls.ERROR: logging.ERROR,
            cls.CRITICAL: logging.CRITICAL
        }.get(level, logging.INFO)

    @classmethod
    def get_color(cls, level: str) -> str:
        return {
            cls.DEBUG: Fore.CYAN,
            cls.VERBOSE: Fore.BLUE,
            cls.INFO: Fore.GREEN,
            cls.WARNING: Fore.YELLOW,
            cls.ERROR: Fore.RED,
            cls.CRITICAL: Fore.RED + Back.WHITE
        }.get(level, Fore.WHITE)


@dataclass
class StackFrame:
    file: Optional[str]
    line: Optional[int]
    function: Optional[str]
    args: Optional[List[Any]]
    class_name: Optional[str]
    call_type: str = 'static'

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'StackFrame':
        return cls(
            file=str(data.get('file')) if data.get('file') else None,
            line=int(data['line']) if data.get('line') is not None else None,
            function=str(data.get('function')) if data.get('function') else None,
            args=data.get('args'),
            class_name=str(data.get('class')) if data.get('class') else None,
            call_type=str(data.get('callType', 'static'))
        )

    def format(self) -> str:
        location = f"{self.file or '?'}:{self.line or '?'}"
        if self.class_name:
            call = f"{self.class_name}{self.call_type}{self.function or ''}"
        else:
            call = self.function or ''

        args_str = ""
        if self.args:
            args_str = f"({', '.join(str(arg) for arg in self.args)})"

        return f"{Fore.BLUE}{call}{Style.RESET_ALL}{args_str} in {Fore.CYAN}{location}{Style.RESET_ALL}"


class ExceptionDetails:
    def __init__(self, name: str, message: str, code: Optional[int],
                 file: Optional[str], line: Optional[int],
                 trace: List[StackFrame], previous: Optional['ExceptionDetails']):
        self.name = name
        self.message = message
        self.code = code
        self.file = file
        self.line = line
        self.trace = trace
        self.previous = previous

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> Optional['ExceptionDetails']:
        if not data:
            return None

        trace = []
        if 'trace' in data and isinstance(data['trace'], list):
            trace = [StackFrame.from_dict(frame) for frame in data['trace']
                     if isinstance(frame, dict)]

        previous = None
        if 'previous' in data and isinstance(data['previous'], dict):
            previous = cls.from_dict(data['previous'])

        return cls(
            name=str(data.get('name', '')),
            message=str(data.get('message', '')),
            code=int(data['code']) if data.get('code') is not None else None,
            file=str(data.get('file')) if data.get('file') else None,
            line=int(data['line']) if data.get('line') is not None else None,
            trace=trace,
            previous=previous
        )

    def format(self, level: int = 0) -> str:
        indent = "    " * level
        parts = []

        # Exception header
        header = f"{indent}{Fore.RED}{self.name}"
        if self.code is not None and 0:
            header += f" {Fore.YELLOW}:{self.code}{Style.RESET_ALL}"

        # Message
        header += f"{Fore.WHITE}:{Style.RESET_ALL} {self.message}"

        # Location
        if self.file and self.line:
            header += f"{Fore.WHITE} at {Style.RESET_ALL}{self.file}:{self.line}"

        parts.append(header)

        # Stack trace
        if self.trace:
            parts.append(f"{indent}{Fore.WHITE}Stack trace:{Style.RESET_ALL}")
            for frame in self.trace:
                parts.append(f"{indent}  → {frame.format()}")

        # Previous exception
        if self.previous:
            parts.append(f"{indent}{Fore.YELLOW}Caused by:{Style.RESET_ALL}")
            parts.append(self.previous.format(level + 1))

        return "\n".join(parts)


class ColoredLogger(logging.Logger):
    def __init__(self, name: str):
        super().__init__(name)
        self.formatter = logging.Formatter(
            f'%(asctime)s {Fore.WHITE}[%(levelname)s]{Style.RESET_ALL} %(message)s',
            datefmt='%Y-%m-%d %H:%M:%S'
        )

        console_handler = logging.StreamHandler()
        console_handler.setFormatter(self.formatter)
        self.addHandler(console_handler)


class MultiProtocolServer:
    def __init__(self, host: str, port: int, working_directory: str):
        self.host = host
        self.port = port
        self.working_directory = working_directory
        self.log_queue: Queue = Queue()
        self.current_date = datetime.now().strftime('%Y-%m-%d')
        self.log_file = None
        self.stop_event = threading.Event()

        os.makedirs(self.working_directory, exist_ok=True)

        # Set up colored logging
        logging.setLoggerClass(ColoredLogger)
        self.logger = logging.getLogger("MultiProtocolServer")
        self.logger.setLevel(logging.DEBUG)

    def _handle_log_event(self, data: Dict[str, Any], address: tuple) -> None:
        """Process and format a structured log event with colors and proper formatting."""
        try:
            app_name = data.get('application_name', 'Unknown')
            timestamp = data.get('timestamp')
            if timestamp:
                try:
                    timestamp = datetime.fromtimestamp(int(timestamp))
                    timestamp = timestamp.strftime('%Y-%m-%d %H:%M:%S')
                except (ValueError, TypeError):
                    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            else:
                timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

            level = data.get('level', 'INFO')
            message = data.get('message', '')

            # Format the log message with colors
            color = LogLevel.get_color(level)
            log_message = f"{color}[{app_name}]{Style.RESET_ALL} {message}"

            # Handle exception if present
            exception_data = data.get('exception')
            if exception_data:
                exception = ExceptionDetails.from_dict(exception_data)
                if exception:
                    log_message += f"\n{exception.format()}"

            # Log with appropriate level
            python_level = LogLevel.to_python_level(level)
            self.logger.log(python_level, log_message)

            # Add to log queue for file logging
            self.log_queue.put({
                "timestamp": timestamp,
                "address": address,
                "data": data
            })

        except Exception as e:
            self.logger.error(f"Error processing log event: {e}", exc_info=True)

    def _handle_data(self, data: bytes, address: tuple) -> None:
        """Process incoming data and attempt to parse as JSON."""
        try:
            decoded_data = data.decode('utf-8').strip()

            try:
                json_data = json.loads(decoded_data)
                # Handle structured log event
                self._handle_log_event(json_data, address)
            except json.JSONDecodeError:
                # Log raw data if not valid JSON
                self.logger.info(f"Received non-JSON data from {address}: {decoded_data}")
                self.log_queue.put({
                    "timestamp": datetime.now().isoformat(),
                    "address": address,
                    "data": decoded_data
                })

        except Exception as e:
            self.logger.error(f"Data handling error: {e}")

    # Rest of the class remains the same...
    def _get_log_file(self):
        date = datetime.now().strftime('%Y-%m-%d')
        if date != self.current_date or self.log_file is None:
            if self.log_file:
                self.log_file.close()
            self.current_date = date
            filename = os.path.join(self.working_directory, f"log{date}.jsonl")
            self.log_file = open(filename, 'a')
        return self.log_file

    def _log_writer(self):
        while not self.stop_event.is_set() or not self.log_queue.empty():
            try:
                data = self.log_queue.get(timeout=1)
                log_file = self._get_log_file()
                json.dump(data, log_file)
                log_file.write('\n')
                log_file.flush()
            except Empty:
                continue
            except Exception as e:
                self.logger.error(f"Error writing to log file: {e}")

    def _handle_tcp_client(self, client_socket, address):
        self.logger.info(f"TCP connection established from {address}")
        try:
            with client_socket:
                while True:
                    data = client_socket.recv(4096)
                    if not data:
                        break
                    self._handle_data(data, address)
        except Exception as e:
            self.logger.error(f"TCP client error: {e}")
        self.logger.info(f"TCP connection closed from {address}")

    def _handle_udp_client(self, data, address):
        try:
            self._handle_data(data, address)
        except Exception as e:
            self.logger.error(f"UDP client error: {e}")

    def _start_tcp_server(self):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as tcp_socket:
            tcp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            tcp_socket.bind((self.host, self.port))
            tcp_socket.listen(5)
            self.logger.debug(f"TCP server running on {self.host}:{self.port}")

            while not self.stop_event.is_set():
                try:
                    client_socket, address = tcp_socket.accept()
                    threading.Thread(target=self._handle_tcp_client,
                                  args=(client_socket, address),
                                  daemon=True).start()
                except Exception as e:
                    self.logger.error(f"TCP server error: {e}")

    def _start_udp_server(self):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp_socket:
            udp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 1024 * 1024)
            udp_socket.bind((self.host, self.port))
            self.logger.debug(f"UDP server running on {self.host}:{self.port}")

            while not self.stop_event.is_set():
                try:
                    data, address = udp_socket.recvfrom(65535)
                    self._handle_udp_client(data, address)
                except Exception as e:
                    self.logger.error(f"UDP server error: {e}")

    def start(self):
        self.logger.info("Starting MultiProtocolServer...")
        threading.Thread(target=self._log_writer, daemon=True).start()
        tcp_thread = threading.Thread(target=self._start_tcp_server, daemon=True)
        udp_thread = threading.Thread(target=self._start_udp_server, daemon=True)
        tcp_thread.start()
        udp_thread.start()

        try:
            tcp_thread.join()
            udp_thread.join()
        except KeyboardInterrupt:
            self.stop()

    def stop(self):
        self.logger.info("Stopping Logging Server...")
        self.stop_event.set()
        if self.log_file:
            self.log_file.close()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Logging Server")
    parser.add_argument("-p", "--port", type=int, default=8080,
                       help="Port to listen on")
    parser.add_argument("-w", "--working-directory", type=str,
                       default="./logs", help="Directory to store log files")
    args = parser.parse_args()

    server = MultiProtocolServer("0.0.0.0", args.port, args.working_directory)
    server.start()