#!/usr/bin/env python3
"""
LogLib Server - A Python CLI program that receives and displays logging events from LogLib2
Supports both TCP and UDP on the same port with message continuation for large payloads.
"""

import argparse
import json
import socket
import select
import sys
import threading
import time
from datetime import datetime
from typing import Dict, Optional, Any
from collections import defaultdict
import re

# ANSI color codes for console output
class Colors:
    RED = '\033[91m'
    GREEN = '\033[92m'
    YELLOW = '\033[93m'
    BLUE = '\033[94m'
    MAGENTA = '\033[95m'
    CYAN = '\033[96m'
    WHITE = '\033[97m'
    GRAY = '\033[90m'
    BOLD = '\033[1m'
    RESET = '\033[0m'

class LogLevel:
    DEBUG = 'DEBUG'
    VERBOSE = 'VERBOSE'
    INFO = 'INFO'
    WARNING = 'WARNING'
    ERROR = 'ERROR'
    CRITICAL = 'CRITICAL'

    @staticmethod
    def get_color(level: str) -> str:
        return {
            LogLevel.DEBUG: Colors.GRAY,
            LogLevel.VERBOSE: Colors.BLUE,
            LogLevel.INFO: Colors.GREEN,
            LogLevel.WARNING: Colors.YELLOW,
            LogLevel.ERROR: Colors.RED,
            LogLevel.CRITICAL: Colors.MAGENTA
        }.get(level.upper(), Colors.WHITE)

class MessageReassembler:
    """Handles message reassembly for continued messages."""

    def __init__(self):
        self.pending_messages: Dict[str, Dict[str, Any]] = {}
        self.continuation_marker = '\x1E'  # ASCII Record Separator

    def process_message(self, data: bytes, client_addr: str) -> Optional[str]:
        """Process incoming message and handle continuation if needed."""
        try:
            message = data.decode('utf-8')
        except UnicodeDecodeError:
            print(f"[ERROR] Failed to decode message from {client_addr}", file=sys.stderr)
            return None

        # Check if this is a continuation message
        if message.startswith(self.continuation_marker):
            return self._handle_continuation(message[1:], client_addr)
        elif message.endswith(self.continuation_marker):
            return self._handle_start_continuation(message[:-1], client_addr)
        else:
            # Regular complete message
            return message

    def _handle_start_continuation(self, message: str, client_addr: str) -> None:
        """Handle the start of a continued message."""
        self.pending_messages[client_addr] = {
            'parts': [message],
            'timestamp': time.time()
        }
        return None

    def _handle_continuation(self, message: str, client_addr: str) -> Optional[str]:
        """Handle continuation of a message."""
        if client_addr not in self.pending_messages:
            print(f"[ERROR] Received continuation without start from {client_addr}", file=sys.stderr)
            return None

        self.pending_messages[client_addr]['parts'].append(message)

        # Check if this is the final part (no continuation marker at end)
        if not message.endswith(self.continuation_marker):
            # Complete message
            complete_message = ''.join(self.pending_messages[client_addr]['parts'])
            del self.pending_messages[client_addr]
            return complete_message
        else:
            # More parts to come
            self.pending_messages[client_addr]['parts'][-1] = message[:-1]  # Remove trailing marker
            return None

    def cleanup_stale_messages(self, max_age: int = 60):
        """Clean up stale pending messages."""
        current_time = time.time()
        stale_clients = []
        for client_addr, data in self.pending_messages.items():
            if current_time - data['timestamp'] > max_age:
                stale_clients.append(client_addr)

        for client_addr in stale_clients:
            print(f"[WARNING] Cleaning up stale message from {client_addr}", file=sys.stderr)
            del self.pending_messages[client_addr]

class LogLibServer:
    def __init__(self, host: str = '0.0.0.0', port: int = 5140, enable_colors: bool = True, show_filenames: bool = False):
        self.host = host
        self.port = port
        self.enable_colors = enable_colors
        self.show_filenames = show_filenames
        self.running = False
        self.reassembler = MessageReassembler()
        self.application_colors = {}
        self.color_index = 0
        self.available_colors = [Colors.BLUE, Colors.GREEN, Colors.CYAN, Colors.YELLOW, Colors.MAGENTA]

        # Create sockets
        self.tcp_socket = None
        self.udp_socket = None
        self.clients = []

    def start(self):
        """Start the LogLib server."""
        try:
            self._setup_sockets()
            self.running = True
            print(f"LogLib Server started on {self.host}:{self.port}")
            print("Listening for TCP and UDP connections...")
            print("Press Ctrl+C to stop\n")

            # Start cleanup thread
            cleanup_thread = threading.Thread(target=self._cleanup_thread, daemon=True)
            cleanup_thread.start()

            self._main_loop()

        except KeyboardInterrupt:
            print("\nShutting down server...")
        except Exception as e:
            print(f"Server error: {e}", file=sys.stderr)
        finally:
            self.stop()

    def stop(self):
        """Stop the server and cleanup resources."""
        self.running = False
        if self.tcp_socket:
            self.tcp_socket.close()
        if self.udp_socket:
            self.udp_socket.close()
        for client in self.clients:
            client.close()

    def _setup_sockets(self):
        """Setup TCP and UDP sockets."""
        # TCP Socket
        self.tcp_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.tcp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.tcp_socket.bind((self.host, self.port))
        self.tcp_socket.listen(10)
        self.tcp_socket.setblocking(False)

        # UDP Socket
        self.udp_socket = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.udp_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.udp_socket.bind((self.host, self.port))
        self.udp_socket.setblocking(False)

    def _main_loop(self):
        """Main server loop handling both TCP and UDP connections."""
        sockets = [self.tcp_socket, self.udp_socket]

        while self.running:
            try:
                ready, _, error = select.select(sockets + self.clients, [], sockets + self.clients, 1.0)

                for sock in ready:
                    if sock == self.tcp_socket:
                        self._handle_tcp_connection()
                    elif sock == self.udp_socket:
                        self._handle_udp_message()
                    elif sock in self.clients:
                        self._handle_tcp_message(sock)

                # Handle socket errors
                for sock in error:
                    if sock in self.clients:
                        self.clients.remove(sock)
                        sock.close()

            except Exception as e:
                print(f"Main loop error: {e}", file=sys.stderr)

    def _handle_tcp_connection(self):
        """Handle new TCP connection."""
        try:
            client_socket, addr = self.tcp_socket.accept()
            client_socket.setblocking(False)
            self.clients.append(client_socket)
            print(f"[TCP] New connection from {addr[0]}:{addr[1]}", file=sys.stderr)
        except Exception as e:
            print(f"TCP connection error: {e}", file=sys.stderr)

    def _handle_tcp_message(self, client_socket):
        """Handle TCP message from client."""
        try:
            data = client_socket.recv(65536)
            if not data:
                # Connection closed
                if client_socket in self.clients:
                    self.clients.remove(client_socket)
                client_socket.close()
                return

            addr = client_socket.getpeername()
            client_addr = f"tcp://{addr[0]}:{addr[1]}"
            message = self.reassembler.process_message(data, client_addr)

            if message:
                self._process_log_event(message, client_addr)

        except Exception as e:
            # Remove faulty client
            if client_socket in self.clients:
                self.clients.remove(client_socket)
            client_socket.close()

    def _handle_udp_message(self):
        """Handle UDP message."""
        try:
            data, addr = self.udp_socket.recvfrom(65536)
            client_addr = f"udp://{addr[0]}:{addr[1]}"
            message = self.reassembler.process_message(data, client_addr)

            if message:
                self._process_log_event(message, client_addr)

        except Exception as e:
            print(f"UDP message error: {e}", file=sys.stderr)

    def _process_log_event(self, message: str, client_addr: str):
        """Process and display a log event."""
        try:
            # Parse JSON log event
            log_data = json.loads(message.strip())
            self._display_log_event(log_data, client_addr)
        except json.JSONDecodeError as e:
            print(f"[ERROR] Invalid JSON from {client_addr}: {e}", file=sys.stderr)
            print(f"[ERROR] Raw message: {repr(message[:200])}", file=sys.stderr)
        except Exception as e:
            print(f"[ERROR] Failed to process log event from {client_addr}: {e}", file=sys.stderr)

    def _display_log_event(self, log_data: Dict[str, Any], client_addr: str):
        """Display a log event in console format."""
        try:
            # Extract basic info
            app_name = log_data.get('application_name', 'Unknown')
            timestamp = log_data.get('timestamp', '')
            level = log_data.get('level', 'INFO').upper()
            message = log_data.get('message', '')
            trace = log_data.get('trace', '')
            exception = log_data.get('exception')

            # Build output string
            output_parts = []

            # Timestamp
            if timestamp:
                if self.enable_colors:
                    output_parts.append(f"{Colors.BOLD}{timestamp}{Colors.RESET}")
                else:
                    output_parts.append(timestamp)

            # Application name with color
            if app_name:
                app_color = self._get_application_color(app_name)
                if self.enable_colors:
                    output_parts.append(f"{app_color}{Colors.BOLD}{app_name}{Colors.RESET}")
                else:
                    output_parts.append(app_name)

            # Log level with color
            level_color = LogLevel.get_color(level)
            if self.enable_colors:
                output_parts.append(f"{level_color}[{level}]{Colors.RESET}")
            else:
                output_parts.append(f"[{level}]")

            # Trace information
            if trace:
                display_trace = self._format_trace_for_log_line(trace)
                if display_trace:
                    if self.enable_colors:
                        output_parts.append(f"{Colors.GRAY}{display_trace}{Colors.RESET}")
                    else:
                        output_parts.append(display_trace)

            # Join parts and add message
            output = ' '.join(output_parts)
            if output:
                output += f" {message}"
            else:
                output = message

            # Determine output stream
            output_stream = sys.stderr if level in ['WARNING', 'ERROR', 'CRITICAL'] else sys.stdout

            print(output, file=output_stream)

            # Handle exceptions
            if exception:
                self._display_exception(exception, output_stream)

        except Exception as e:
            print(f"[ERROR] Failed to display log event: {e}", file=sys.stderr)

    def _format_trace_for_log_line(self, trace: str) -> str:
        """Format trace information for the main log line, optionally hiding file paths."""
        if not trace:
            return ""
        
        # If show_filenames is enabled, return the full trace
        if self.show_filenames:
            return trace
            
        # Extract class and method from trace, removing file path
        # Expected format: "ClassName\Method (file:line)" or similar
        import re
        
        # Try to extract just the class and method, removing file path in parentheses
        # Pattern matches: "Class\Method (anything)" -> "Class\Method"
        pattern = r'^([^(]+)'
        match = re.match(pattern, trace)
        if match:
            return match.group(1).strip()
        
        # If no match, return the trace as-is (fallback)
        return trace

    def _display_exception(self, exception: Dict[str, Any], output_stream=sys.stdout):
        """Display exception details with full stack trace."""
        try:
            name = exception.get('name', 'Exception')
            message = exception.get('message', '')
            code = exception.get('code')
            file_path = exception.get('file')
            line = exception.get('line')
            trace = exception.get('trace', [])
            previous = exception.get('previous')

            # Exception header
            if self.enable_colors:
                output = f"\n{Colors.RED}{name}{Colors.RESET}"
            else:
                output = f"\n{name}"

            if code is not None and code != 0:
                output += f" ({code})"

            if message:
                output += f": {message}"

            # File and line - always show for exceptions since they're important for debugging
            if file_path:
                output += f"\n    File: {file_path}"
                if line is not None and line != 0:
                    output += f":{line}"

            print(output, file=output_stream)

            # Stack trace
            if trace:
                print("  Stack Trace:", file=output_stream)
                for i, frame in enumerate(trace):
                    trace_output = self._format_stack_frame(frame, i)
                    print(f"    {trace_output}", file=output_stream)

            # Previous exception
            if previous:
                print("\nCaused by:", file=output_stream)
                self._display_exception(previous, output_stream)

        except Exception as e:
            print(f"[ERROR] Failed to display exception: {e}", file=sys.stderr)

    def _format_stack_frame(self, frame: Dict[str, Any], index: int) -> str:
        """Format a single stack trace frame."""
        file_path = frame.get('file', '<unknown>')
        line = frame.get('line')
        function = frame.get('function', '<unknown>')
        class_name = frame.get('class')
        call_type = frame.get('call_type', '::')
        args = frame.get('args', [])

        # Build frame description
        parts = []

        # File and line - always show for stack traces since they're important for debugging
        if file_path != '<unknown>':
            file_part = file_path
            if line is not None:
                file_part += f":{line}"
            parts.append(file_part)

        # Function call
        if class_name:
            call = f"{class_name}{call_type}{function}"
        else:
            call = function

        # Add arguments (simplified)
        if args and isinstance(args, list) and len(args) > 0:
            arg_count = len(args)
            call += f"(...{arg_count} args)"
        else:
            call += "()"

        parts.append(call)

        return f"#{index}: " + " in ".join(parts)

    def _get_application_color(self, app_name: str) -> str:
        """Get or assign a color for an application."""
        if not self.enable_colors:
            return ''

        if app_name not in self.application_colors:
            color = self.available_colors[self.color_index % len(self.available_colors)]
            self.application_colors[app_name] = color
            self.color_index += 1

        return self.application_colors[app_name]

    def _cleanup_thread(self):
        """Background thread to cleanup stale messages."""
        while self.running:
            time.sleep(30)  # Cleanup every 30 seconds
            self.reassembler.cleanup_stale_messages()

def main():
    parser = argparse.ArgumentParser(description='LogLib Server - Receive and display LogLib2 logging events')
    parser.add_argument('-H', '--host', default='0.0.0.0', help='Host to bind to (default: 0.0.0.0)')
    parser.add_argument('-p', '--port', type=int, default=5140, help='Port to bind to (default: 5140)')
    parser.add_argument('--no-colors', action='store_true', help='Disable colored output')
    parser.add_argument('--show-filenames', action='store_true', help='Show full file paths in log traces and exceptions (default: disabled)')
    parser.add_argument('--version', action='version', version='LogLib Server 1.0.0')

    args = parser.parse_args()

    server = LogLibServer(
        host=args.host,
        port=args.port,
        enable_colors=not args.no_colors,
        show_filenames=args.show_filenames
    )

    try:
        server.start()
    except Exception as e:
        print(f"Failed to start server: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == '__main__':
    main()
