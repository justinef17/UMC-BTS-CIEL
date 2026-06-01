#include "ihm_locale.h"
#include "QtSql/QSqlError"
#include "QtSql/QSqlQuery"
#include "QtSql/QSqlDatabase"
#include "ui_ihm_locale.h"
#include <QDebug>
#include <QMessageBox>
#include <QTextStream>
#include <QFile>

IhmLocale::IhmLocale(QWidget *parent) : QMainWindow(parent), ui(new Ui::IhmLocale) {
    ui->setupUi(this);
    qDebug() << "Driver disponibles :" << QSqlDatabase::drivers();
    qDebug() << "Chemins plugins :" << QCoreApplication::libraryPaths();
    networkManager = new QNetworkAccessManager(this);
    timerAcquisition = new QTimer(this);
    connect(timerAcquisition, &QTimer::timeout, this, &IhmLocale::actualiserDonnees);
    ui->btnArreter->setEnabled(false);
}
IhmLocale::~IhmLocale() { delete ui; }

void IhmLocale::genererFichierCSV() {
    QFile file("/tmp/mesures.csv");
    if (file.open(QIODevice::WriteOnly | QIODevice::Text)) {
        QTextStream out(&file);
        //
        QSqlQuery query("SELECT * FROM mesures WHERE synchro = 0", dbLocale);
        while (query.next()) {
            out << query.value("bat_tension").toString() << ";"
                << query.value("bat_courant").toString() << ";"
                << query.value("soc").toString() << ";"
                << query.value("ac_out_courant").toString() << "\n";
        }
        file.close();
    }
}
// RÉPLICATION : ENVOI FTPS Vers Alwaysdata server
void IhmLocale::on_btnExportCSV_clicked() {
    genererFichierCSV();
    QUrl url("ftps://192.168.1.35:21/CAPTURES_CSV/mesures.csv");
    url.setUserName("Admin");
    url.setPassword("bonjour");
    url.setPort(21);
    QNetworkRequest request(url);
    QFile *fileToSend = new QFile("/tmp/mesures.csv");
    fileToSend->open(QIODevice::ReadOnly);
    QNetworkReply *reply = networkManager->put(request, fileToSend);
    connect(reply, &QNetworkReply::finished, [reply, fileToSend, this]() {
        if (reply->error() == QNetworkReply::NoError) {
            ui->lblStatus->setText("Réplication réussie vers FTPS");
            // synchronisé dans la BDD locale
        } else {
            QMessageBox::warning(this, "Erreur FTPS", reply->errorString());
        }
        fileToSend->close();
        fileToSend->deleteLater();
        reply->deleteLater();
    });
}

// BOUTON CONNECTER
void IhmLocale::on_btnConnecter_clicked() {
    if (QSqlDatabase::contains("locale"))
        QSqlDatabase::removeDatabase("locale");

    //dbLocale = QSqlDatabase::addDatabase("QMYSQL", "locale");
    //dbLocale.setHostName("192.168.0.15"); // BDD locale sur la raspberry
    //dbLocale.setDatabaseName("composteur_energie");
    //dbLocale.setUserName("root");
    //dbLocale.setPassword("Energie1");
    dbLocale = QSqlDatabase::addDatabase("QMYSQL", "locale");
    dbLocale.setHostName("127.0.0.1");       // localhost PC
    dbLocale.setPort(3306);
    dbLocale.setDatabaseName("composteur_energie");
    dbLocale.setUserName("root");
    dbLocale.setPassword("bonjour"); // mot de passe
    dbLocale.setConnectOptions("SSL_DISABLED=1");
    if (!dbLocale.open()) {
        QMessageBox::critical(this, "Erreur BDD", dbLocale.lastError().text());
        return;
    }
    ui->btnDemarrer->setEnabled(true);
    QMessageBox::information(this, "Succès", "Connecté à la BDD Locale");
}

//BOUTON DEMARRER
void IhmLocale::on_btnDemarrer_clicked(){
    if (!QSqlDatabase::database("locale").isOpen()) {
        QMessageBox::warning(this, "Erreur","Connecter la Bdd dans un premier temps");
        return;
    }

    int intervalle = ui->sbPeriode->value()*60*1000;
    timerAcquisition->start(intervalle);

    ui->lblStatus->setText("Acquisition en cours");
    ui->lblStatus->setStyleSheet("background-color: green; color: white;");

    ui->btnDemarrer->setEnabled(false);
    ui->btnArreter->setEnabled(true);

    actualiserDonnees();
}

//BOUTON ARRETER
void IhmLocale::on_btnArreter_clicked(){
    timerAcquisition->stop();

    ui->lblStatus->setText("Acquisition arrêtée");
    ui->lblStatus->setStyleSheet("background-color: red; color: white;");

    ui->btnArreter->setEnabled(false);
    ui->btnDemarrer->setEnabled(true);
}

// TIMER -> acquisition replication auto
void IhmLocale::actualiserDonnees() {
    QSqlDatabase dbloc = QSqlDatabase::database("locale");

    if (!dbloc.isOpen()) {
        qDebug() << "BDD non ouverte !";
        return;
    }

    QSqlQuery query(dbloc);
    query.exec("SELECT * FROM mesures ORDER BY horodatage DESC LIMIT 1");

    qDebug() << "Erreur query :" << query.lastError().text();
    qDebug() << "Nb résultats :" << query.size();

    if (query.next())
    {
        // Mise à jour des QLCDNumber (Ergonomie)
        ui->lcdBatTension->display(query.value("bat_tension").toDouble());
        ui->lcdBatCourant->display(query.value("bat_courant").toDouble());
        ui->lcdAcOutTension->display(query.value("ac_out_tension").toDouble());
        ui->lcdAcOutCourant->display(query.value("ac_out_courant").toDouble());
        // Barre de progression SOC
        int soc = query.value("soc").toInt();
        ui->pbSOC->setValue(soc);
        //SEUIL D'ALARME
        if (soc < ui->sbSeuilAlarme->value()) {
            ui->lblStatus->setText("ALERTE : Énergie");
            ui->lblStatus->setStyleSheet("background-color: red; color: white;");
        } else {
            ui->lblStatus->setText("Système OK");
            ui->lblStatus->setStyleSheet("background-color: green; color: white;");
        }
    }
    //repliquerVersAlwaysData();
    else
    {
        qDebug() << "Aucune donnée trouvée !";
    }
}

//Répliquer vers alwaysdata
    void IhmLocale::repliquerVersAlwaysData()
{
    if (QSqlDatabase::contains("alwaysdata"))
        QSqlDatabase::removeDatabase("alwaysdata");

    QSqlDatabase dbDistante = QSqlDatabase::addDatabase("QMYSQL", "alwaysdata");
    dbDistante.setHostName("mysql-gestionenergiemaupertuis.alwaysdata.net");
    dbDistante.setPort(3306);
    dbDistante.setUserName("gestionenergiemaupertuis");
    dbDistante.setPassword("-!4mN6l0Q\3g=wRqM)DUA~{W/i$OY/lvtNyuTqBe4j5;CEl.n2{{;I3wzM%wTrpZ");

    if (!dbDistante.open())
    {
        QMessageBox::warning(this, "Erreur avec Alwaysdata", dbDistante.lastError().text());
        return;
    }

    QSqlDatabase dbloc = QSqlDatabase::database("locale");
    QSqlQuery queryLocale("SELECT * FROM mesures WHERE synchro = 0");

    while (queryLocale.next())
    {
        QSqlQuery queryInsert(dbDistante);
        queryInsert.prepare(
            "INSERT INTO mesures "
            "(bat_tension, bat_courant, soc, ac_out_courant, horodatage) "
            "ac_ou_tension, ac_out_courant, horodatage)"
            "VALUES (:tension, :courant, :soc, :ac, :horo)"

            );

        queryInsert.bindValue("tension", queryLocale.value("bat_tension"));
        queryInsert.bindValue("courant", queryLocale.value("bat_courant"));
        queryInsert.bindValue("soc", queryLocale.value("soc"));
        queryInsert.bindValue("ac_in_t", queryLocale.value("sac_in_tension"));
        queryInsert.bindValue("ac_in_c", queryLocale.value("sac_in_courant"));
        queryInsert.bindValue("ac_out_t", queryLocale.value("sac_out_tension"));
        queryInsert.bindValue("ac_out_c", queryLocale.value("sac_out_courant"));
        queryInsert.bindValue("ac", queryLocale.value("ac_out"));
        queryInsert.bindValue("horo", queryLocale.value("horodatage"));
        queryInsert.exec();
    }
    QSqlQuery(dbloc.exec("UPDATE mesures SET synchro = 1 WHERE synchro = 0"));
    dbDistante.close();

    ui->lblStatus->setText("Replication reussie");
    ui->lblStatus->setStyleSheet("background-color: green; color :white;");
}
