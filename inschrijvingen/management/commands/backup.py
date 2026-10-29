from django.core.management.base import BaseCommand

from inschrijvingen import backup


class Command(BaseCommand):
    help = "Maakt een volledige backup (zip) in BACKUP_DIR. --bewaar N: automatische backups ouder dan N dagen wissen."

    def add_arguments(self, parser):
        parser.add_argument("--automatisch", action="store_true")
        parser.add_argument("--bewaar", type=int, default=0)

    def handle(self, *args, automatisch=False, bewaar=0, **opts):
        pad = backup.maak("automatisch" if automatisch else "manueel")
        if bewaar:
            backup.opruimen(bewaar)
        self.stdout.write(str(pad))
