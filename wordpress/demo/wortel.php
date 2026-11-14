<?php
// Wortel van de container (leden-demo.khzs.be/): de demo staat op DEMO_URL (https://khzs.be/leden-demo/).
header('Location: ' . rtrim(getenv('DEMO_URL') ?: '/leden-demo', '/') . '/', true, 302);
