(function(){
  const DATA = {
    URBANO: [
      "Chiclayo",
      "Leonardo Ortiz",
      "La Victoria",
      "Santa Victoria"
    ],
    PUEBLOS: [
      "Lambayeque",
      "Mochumi",
      "Tucume",
      "Illimo",
      "Nueva Arica",
      "Jayanca",
      "Pacora",
      "Morrope",
      "Motupe",
      "Olmos",
      "Salas"
    ],
    PLAYAS: [
      "San Jose",
      "Santa Rosa",
      "Pimentel",
      "Reque",
      "Monsefu",
      "Eten",
      "Puerto Eten"
    ],
    COOPERATIVAS: [
      "Pomalca",
      "Tuman",
      "Patapo",
      "Pucala",
      "Saltur",
      "Chongoyape"
    ],
    EXCOOPERATIVAS: [
      "Ucupe",
      "Mocupe",
      "Zaña",
      "Cayalti",
      "Oyotun",
      "Lagunas",
      "Saña"
    ],
    FERREÑAFE: [
      "Ferreñafe",
      "Picsi",
      "Pitipo",
      "Motupillo",
      "Pueblo Nuevo"
    ]
  };

  const deepFreeze = (obj) => {
    Object.getOwnPropertyNames(obj).forEach((name) => {
      const value = obj[name];
      if (value && typeof value === 'object') Object.freeze(value);
    });
    return Object.freeze(obj);
  };

  const RUTAS = deepFreeze(DATA);

  if (!Object.prototype.hasOwnProperty.call(window, 'RUTAS')) {
    Object.defineProperty(window, 'RUTAS', {
      value: RUTAS,
      writable: false,
      configurable: false,
      enumerable: true
    });
  }
})();
