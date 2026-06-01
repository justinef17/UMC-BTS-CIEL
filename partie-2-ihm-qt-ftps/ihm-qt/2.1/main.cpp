#include "ihm_locale.h"
#include <QApplication>

int main(int argc, char *argv[])
{
    QApplication a(argc, argv);
    IhmLocale w;
    w.show();
    return a.exec();
}