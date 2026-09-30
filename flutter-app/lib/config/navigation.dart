import 'package:flutter/material.dart';

/// Clé de navigation globale : permet au client HTTP d'afficher la saisie du
/// PIN (bottom-sheet) quelle que soit la page à l'origine de l'opération.
final GlobalKey<NavigatorState> fpNavigatorKey = GlobalKey<NavigatorState>();
