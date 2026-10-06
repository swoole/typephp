#include <phpx.h>

#if defined(_WIN32)
#include <windows.h>
#include <io.h>
#else
#include <sys/ioctl.h>
#include <termios.h>
#include <unistd.h>
#include <cstdlib>
#include <cstring>
#endif

using namespace php;

#if defined(_WIN32)
static DWORD orig_console_mode = 0;
static bool raw_mode_active = false;

static void cleanup_terminal_at_exit()
{
    if (raw_mode_active) {
        HANDLE hStdin = GetStdHandle(STD_INPUT_HANDLE);
        if (hStdin != INVALID_HANDLE_VALUE && hStdin != NULL) {
            SetConsoleMode(hStdin, orig_console_mode);
        }
        raw_mode_active = false;
    }
}
#else
static struct termios orig_termios;
static bool raw_mode_active = false;

static void cleanup_terminal_at_exit()
{
    if (raw_mode_active) {
        tcsetattr(STDIN_FILENO, TCSAFLUSH, &orig_termios);
        raw_mode_active = false;
    }
}
#endif

Int php_terminal_get_width()
{
#if defined(_WIN32)
    HANDLE hStdout = GetStdHandle(STD_OUTPUT_HANDLE);
    if (hStdout != INVALID_HANDLE_VALUE && hStdout != NULL) {
        CONSOLE_SCREEN_BUFFER_INFO csbi;
        if (GetConsoleScreenBufferInfo(hStdout, &csbi)) {
            return static_cast<Int>(csbi.srWindow.Right - csbi.srWindow.Left + 1);
        }
    }
    return 80;
#else
    struct winsize ws;
    if (ioctl(STDOUT_FILENO, TIOCGWINSZ, &ws) == 0 && ws.ws_col > 0) {
        return static_cast<Int>(ws.ws_col);
    }
    return 80;
#endif
}

Int php_terminal_get_height()
{
#if defined(_WIN32)
    HANDLE hStdout = GetStdHandle(STD_OUTPUT_HANDLE);
    if (hStdout != INVALID_HANDLE_VALUE && hStdout != NULL) {
        CONSOLE_SCREEN_BUFFER_INFO csbi;
        if (GetConsoleScreenBufferInfo(hStdout, &csbi)) {
            return static_cast<Int>(csbi.srWindow.Bottom - csbi.srWindow.Top + 1);
        }
    }
    return 24;
#else
    struct winsize ws;
    if (ioctl(STDOUT_FILENO, TIOCGWINSZ, &ws) == 0 && ws.ws_row > 0) {
        return static_cast<Int>(ws.ws_row);
    }
    return 24;
#endif
}

Bool php_terminal_isatty(Int fd)
{
#if defined(_WIN32)
    HANDLE h = (fd == 0) ? GetStdHandle(STD_INPUT_HANDLE) :
               ((fd == 1) ? GetStdHandle(STD_OUTPUT_HANDLE) : GetStdHandle(STD_ERROR_HANDLE));
    if (h == INVALID_HANDLE_VALUE || h == NULL) {
        return false;
    }
    DWORD mode;
    return GetConsoleMode(h, &mode) != 0;
#else
    return isatty(static_cast<int>(fd)) != 0;
#endif
}

Bool php_terminal_set_raw_mode(Bool enable)
{
#if defined(_WIN32)
    HANDLE hStdin = GetStdHandle(STD_INPUT_HANDLE);
    if (hStdin == INVALID_HANDLE_VALUE || hStdin == NULL) {
        return false;
    }

    if (enable) {
        if (!raw_mode_active) {
            if (!GetConsoleMode(hStdin, &orig_console_mode)) {
                return false;
            }
            atexit(cleanup_terminal_at_exit);
        }
        DWORD raw_mode = orig_console_mode & ~(ENABLE_LINE_INPUT | ENABLE_ECHO_INPUT | ENABLE_PROCESSED_INPUT);
        if (SetConsoleMode(hStdin, raw_mode)) {
            raw_mode_active = true;
            return true;
        }
        return false;
    } else {
        if (raw_mode_active) {
            SetConsoleMode(hStdin, orig_console_mode);
            raw_mode_active = false;
        }
        return true;
    }
#else
    if (enable) {
        if (!raw_mode_active) {
            if (tcgetattr(STDIN_FILENO, &orig_termios) == -1) {
                return false;
            }
            atexit(cleanup_terminal_at_exit);
        }
        struct termios raw = orig_termios;
        raw.c_iflag &= ~(BRKINT | ICRNL | INPCK | ISTRIP | IXON);
        raw.c_oflag &= ~(OPOST);
        raw.c_cflag |= (CS8);
        raw.c_lflag &= ~(ECHO | ICANON | IEXTEN | ISIG);
        raw.c_cc[VMIN] = 1;
        raw.c_cc[VTIME] = 0;
        if (tcsetattr(STDIN_FILENO, TCSAFLUSH, &raw) == 0) {
            raw_mode_active = true;
            return true;
        }
        return false;
    } else {
        if (raw_mode_active) {
            tcsetattr(STDIN_FILENO, TCSAFLUSH, &orig_termios);
            raw_mode_active = false;
        }
        return true;
    }
#endif
}

String php_terminal_read_char()
{
#if defined(_WIN32)
    HANDLE hStdin = GetStdHandle(STD_INPUT_HANDLE);
    if (hStdin == INVALID_HANDLE_VALUE || hStdin == NULL) {
        return String("");
    }
    INPUT_RECORD rec;
    DWORD count;
    while (ReadConsoleInputW(hStdin, &rec, 1, &count) && count > 0) {
        if (rec.EventType == KEY_EVENT && rec.Event.KeyEvent.bKeyDown) {
            WCHAR wc = rec.Event.KeyEvent.uChar.UnicodeChar;
            if (wc != 0) {
                char utf8[8] = {0};
                int len = WideCharToMultiByte(CP_UTF8, 0, &wc, 1, utf8, sizeof(utf8) - 1, NULL, NULL);
                if (len > 0) {
                    return String(utf8, static_cast<size_t>(len));
                }
            }
        }
    }
    return String("");
#else
    char c = 0;
    ssize_t n = read(STDIN_FILENO, &c, 1);
    if (n > 0) {
        return String(&c, 1);
    }
    return String("");
#endif
}

String php_terminal_read_secret(String prompt)
{
    if (!prompt.empty()) {
        fwrite(prompt.data(), 1, prompt.length(), stdout);
        fflush(stdout);
    }

#if defined(_WIN32)
    HANDLE hStdin = GetStdHandle(STD_INPUT_HANDLE);
    DWORD mode = 0;
    bool has_console = (hStdin != INVALID_HANDLE_VALUE && hStdin != NULL && GetConsoleMode(hStdin, &mode));
    if (has_console) {
        SetConsoleMode(hStdin, mode & (~ENABLE_ECHO_INPUT));
    }
    char buf[2048] = {0};
    size_t len = 0;
    if (fgets(buf, sizeof(buf), stdin) != nullptr) {
        len = strlen(buf);
        while (len > 0 && (buf[len - 1] == '\r' || buf[len - 1] == '\n')) {
            buf[--len] = '\0';
        }
    }
    if (has_console) {
        SetConsoleMode(hStdin, mode);
    }
    printf("\n");
    fflush(stdout);
    return String(buf, len);
#else
    struct termios oldt, newt;
    bool has_tty = (tcgetattr(STDIN_FILENO, &oldt) == 0);
    if (has_tty) {
        newt = oldt;
        newt.c_lflag &= ~(ECHO);
        tcsetattr(STDIN_FILENO, TCSANOW, &newt);
    }
    char buf[2048] = {0};
    size_t len = 0;
    if (fgets(buf, sizeof(buf), stdin) != nullptr) {
        len = strlen(buf);
        while (len > 0 && (buf[len - 1] == '\r' || buf[len - 1] == '\n')) {
            buf[--len] = '\0';
        }
    }
    if (has_tty) {
        tcsetattr(STDIN_FILENO, TCSANOW, &oldt);
    }
    printf("\n");
    fflush(stdout);
    return String(buf, len);
#endif
}

Bool php_terminal_set_title(String title)
{
#if defined(_WIN32)
    int wlen = MultiByteToWideChar(CP_UTF8, 0, title.data(), -1, NULL, 0);
    if (wlen <= 0) {
        return false;
    }
    auto *wstr = new WCHAR[wlen];
    MultiByteToWideChar(CP_UTF8, 0, title.data(), -1, wstr, wlen);
    BOOL ok = SetConsoleTitleW(wstr);
    delete[] wstr;
    return ok != 0;
#else
    printf("\033]0;%s\007", title.data());
    fflush(stdout);
    return true;
#endif
}

Bool php_terminal_supports_color()
{
    const char *term = std::getenv("TERM");
    if (term != nullptr && std::strcmp(term, "dumb") == 0) {
        return false;
    }
    const char *no_color = std::getenv("NO_COLOR");
    if (no_color != nullptr && no_color[0] != '\0') {
        return false;
    }
    return true;
}
