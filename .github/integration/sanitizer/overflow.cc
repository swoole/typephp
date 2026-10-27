// Deliberately faulty canary: CI must observe a real ASan report as PHPT FAIL.
int main(int argc, char **)
{
    volatile int *values = new int[argc];
    values[argc] = 42;
    delete[] values;
    return 0;
}
