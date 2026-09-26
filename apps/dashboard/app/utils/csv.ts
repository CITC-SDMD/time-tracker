/**
 * Reads CSV text into rows of cells: commas between cells, double quotes around a cell that holds a comma, a quote
 * (written as "") or a line break; a leading byte-order mark and blank lines are ignored.
 */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  const source = text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text

  for (let i = 0; i < source.length; i++) {
    const c = source[i]!
    if (quoted) {
      if (c === '"' && source[i + 1] === '"') {
        cell += '"'
        i++
      }
      else if (c === '"') {
        quoted = false
      }
      else {
        cell += c
      }
    }
    else if (c === '"') {
      quoted = true
    }
    else if (c === ',') {
      row.push(cell)
      cell = ''
    }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && source[i + 1] === '\n')
        i++
      row.push(cell)
      cell = ''
      rows.push(row)
      row = []
    }
    else {
      cell += c
    }
  }
  if (cell !== '' || row.length) {
    row.push(cell)
    rows.push(row)
  }

  return rows.filter(r => r.some(value => value.trim() !== ''))
}
